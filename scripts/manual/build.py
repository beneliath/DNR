#!/usr/bin/env python3
"""Build the illustrated MOED manual from the captured guide and screenshots.

Dependencies: reportlab, pypdf, lxml, Pillow. No network or application access.
"""
from __future__ import annotations
import argparse, hashlib, html, json, re, shutil, unicodedata
from pathlib import Path
from lxml import html as LH, etree
from PIL import Image as PILImage
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, PageBreak, Table, TableStyle, Image, KeepTogether, CondPageBreak, Flowable, Preformatted, NextPageTemplate, FrameBreak, FrameSplitter
from pypdf import PdfReader, PdfWriter

ROOT=Path(__file__).resolve().parents[2]
DATA=ROOT/'docs/user-manual'
OUT=ROOT/'output/pdf/moed-comprehensive-user-manual.pdf'
W,H=612,792
M=42
CW=W-2*M
GUTTER=24
COL=(CW-GUTTER)/2
TEAL=colors.HexColor('#0f766e'); INK=colors.HexColor('#172033'); MUTED=colors.HexColor('#667085')
BORDER=colors.HexColor('#dfe4ec'); PALE=colors.HexColor('#e7f6f3'); BLUE=colors.HexColor('#2457d6')
FONTDIR=Path('/System/Library/Fonts/Supplemental')
for name,file in [('Body','Arial.ttf'),('Bold','Arial Bold.ttf'),('Italic','Arial Italic.ttf')]:
    if (FONTDIR/file).exists():pdfmetrics.registerFont(TTFont(name,str(FONTDIR/file)))
    else:pdfmetrics.registerFont(pdfmetrics.Font(name,{'Body':'Helvetica','Bold':'Helvetica-Bold','Italic':'Helvetica-Oblique'}[name],'WinAnsiEncoding'))
pdfmetrics.registerFontFamily('Body',normal='Body',bold='Bold',italic='Italic',boldItalic='Bold')
STYLE={
 'body':ParagraphStyle('body',fontName='Body',fontSize=9.2,leading=13,textColor=INK,spaceAfter=7),
 'small':ParagraphStyle('small',fontName='Body',fontSize=8,leading=11,textColor=MUTED,spaceAfter=6),
 'caption':ParagraphStyle('caption',fontName='Body',fontSize=7.5,leading=10,textColor=MUTED,spaceAfter=9),
 'h2':ParagraphStyle('h2',fontName='Bold',fontSize=22,leading=26,textColor=INK,spaceAfter=13,keepWithNext=True),
 'h3':ParagraphStyle('h3',fontName='Bold',fontSize=14,leading=18,textColor=INK,spaceBefore=12,spaceAfter=7,keepWithNext=True),
 'h4':ParagraphStyle('h4',fontName='Bold',fontSize=10.5,leading=14,textColor=TEAL,spaceBefore=10,spaceAfter=5,keepWithNext=True),
 'kicker':ParagraphStyle('kicker',fontName='Bold',fontSize=8,leading=11,textColor=TEAL,spaceAfter=10,tracking=1.4,keepWithNext=True),
 'intro':ParagraphStyle('intro',fontName='Body',fontSize=11,leading=16,textColor=MUTED,spaceAfter=16),
 'step':ParagraphStyle('step',fontName='Body',fontSize=9.2,leading=13,textColor=INK,leftIndent=24,firstLineIndent=-24,spaceAfter=7),
 'cell':ParagraphStyle('cell',fontName='Body',fontSize=7.8,leading=10.5,textColor=INK),
 'toc':ParagraphStyle('toc',fontName='Body',fontSize=11,leading=16,textColor=INK,spaceAfter=0),
 'code':ParagraphStyle('code',fontName='Courier',fontSize=7,leading=9.5,textColor=INK,backColor=colors.HexColor('#f6f7fb'),borderPadding=7,leftIndent=8,rightIndent=8,spaceBefore=7,spaceAfter=14),
}
STYLE['table_cell']=ParagraphStyle('table-cell',parent=STYLE['body'],fontSize=9,leading=12,spaceAfter=0)
for style in STYLE.values():
    style.splitLongWords=0
    style.embeddedHyphenation=0
    style.uriWasteReduce=0
    style.hyphenationLang=None
REVERSE={key:ParagraphStyle('reverse-'+key,parent=value,textColor=colors.white) for key,value in STYLE.items()}
REVERSE['code'].backColor=colors.black

CHAPTERS=json.loads((DATA/'online-chapters.json').read_text())
SHOTS=json.loads((DATA/'screenshots.json').read_text())
VERSION=(ROOT/'VERSION').read_text().strip()
EDITION_DATE='September 10, 2026'
README_BYTES=(ROOT/'README.md').read_bytes()
README=json.loads((DATA/'readme-appendix.json').read_text())
if README['sha256']!=hashlib.sha256(README_BYTES).hexdigest():
    raise RuntimeError('README snapshot is stale. Run node scripts/manual/render-readme.cjs with the marked module available.')
README_ROOT=LH.fragment_fromstring(README['html'],create_parent='div')
README_HEADINGS=[]
for index,element in enumerate(README_ROOT.xpath('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6'),1):
    element.set('data-pdf-id','readme-section-'+str(index))
    README_HEADINGS.append({'title':' '.join(element.text_content().split()),'key':element.get('data-pdf-id')})
CH_IDS={c['id']:'chapter-'+str(i+1) for i,c in enumerate(CHAPTERS)}
ROUTE_CH={
 'help.php':1,'dashboard.php':3,'inquiries.php':4,'add_inquiry.php':4,'edit_inquiry.php':4,'view_inquiry.php':4,'convert_inquiry.php':4,'compose_inquiry_email.php':4,
 'engagements.php':5,'index.php':5,'edit_engagement.php':5,'close_engagement.php':5,'short_links.php':5,
 'organizations.php':6,'contacts.php':6,'speakers.php':6,'add_contact.php':6,'add_organization.php':6,
 'tasks.php':7,'standard_tasks.php':7,'inbound_mail.php':8,'map.php':9,'view_calendar.php':9,
 'profile.php':10,'two_factor_settings.php':10,'mattermost.php':11,'users.php':12,'audit_log.php':12,'database_maintenance.php':12,'operations.php':12,
}
RELATED={1:[2,3,10],2:[10,12],3:[4,5,7],4:[5,7,8],5:[6,7,8,9],6:[4,5,8],7:[3,5,9],8:[4,5,11],9:[5,7,10],10:[2,9,12],11:[5,7,8],12:[2,10,13],13:[1,5,8,12]}
SHORT=['Getting oriented','Roles and access','Daily dashboard','Booking pipeline','Engagements','People and organizations','Work queue','Chron and email','Map and calendar','Profile and security','Mattermost','Administration','Troubleshooting']

def clean(s):
    substitutions={'\u2014':' - ','\u2013':'-','\u2011':'-','\u00a0':' ','\u2019':"'",'\u2018':"'",'\u201c':'"','\u201d':'"','→':' > ','←':' < ','✓':'Yes','✉️':'Email','📝':'Chron','◇':'','↺':'Restore','◉':'View','✎':'Edit','▶':'Start','□':'Archive','×':'x'}
    for a,b in substitutions.items():s=s.replace(a,b)
    return ''.join(c for c in s if ord(c)<0x1f000 and c!='\ufe0f')

def esc(s):
    s=clean(s)
    # ReportLab draws these mixed-direction lines from left to right. Reverse
    # Hebrew grapheme clusters visually while retaining their vowel marks.
    def hebrew_visual(match):
        clusters=[]
        for char in match.group():
            if unicodedata.combining(char) and clusters:clusters[-1]+=char
            else:clusters.append(char)
        return ''.join(reversed(clusters))
    s=re.sub(r'[\u0590-\u05ff]+',hebrew_visual,s)
    return html.escape(s,quote=False)
def text_of(e):return ' '.join(e.text_content().split())
def inline(e):
    value=esc(e.text or '')
    for c in e:
        inner=inline(c)
        if c.tag in ('strong','b'):value+='<b>'+inner+'</b>'
        elif c.tag in ('em','i'):value+='<i>'+inner+'</i>'
        elif c.tag=='br':value+='<br/>'
        elif c.tag in ('code','kbd'):value+='<font color="#0f766e">'+inner+'</font>'
        elif c.tag=='a':
            href=c.get('href',''); key=None
            if href.startswith('#'):key=CH_IDS.get(href[1:])
            elif href.split('?')[0] in ROUTE_CH:key='chapter-'+str(ROUTE_CH[href.split('?')[0]])
            value+=f'<link href="#{key}" color="#2457d6">{inner}</link>' if key else inner
        else:value+=inner
        value+=esc(c.tail or '')
    return value

class UnbrokenParagraph(Paragraph):
    def draw(self):
        if getattr(self,'_splitLongWordCount',0) or getattr(self,'_hyphenations',0):
            raise RuntimeError('Unexpected word break on page '+str(self.canv.getPageNumber())+': '+self.getPlainText())
        super().draw()

def p(s,style='body'):return UnbrokenParagraph(s,STYLE[style])
def rp(s,style='body'):
    paragraph=UnbrokenParagraph(re.sub(r'color="#[0-9a-fA-F]{6}"','color="#ffffff"',s),REVERSE[style])
    if paragraph.minWidth()>COL-paragraph.style.leftIndent-paragraph.style.rightIndent:
        paragraph.full_width=True
        paragraph.width_label=paragraph.getPlainText()[:100]
    return paragraph
def slug(s):return re.sub('[^a-z0-9]+','-',clean(s).lower()).strip('-')
def heading(s,key,level=1,style='h3',chapter=None,reverse=False):
    obj=(rp if reverse else p)(esc(s),style);obj.dest=key;obj.outline_title=clean(s);obj.outline_level=level;obj.chapter=chapter
    return obj
def card(title,body,warning=False):
    cells=[p(esc(title),'h4')]+[p(esc(s)) for s in body]
    t=Table([[cells]],colWidths=[COL],hAlign='LEFT')
    t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,-1),colors.HexColor('#fff3d8') if warning else PALE),('BOX',(0,0),(-1,-1),.5,BORDER),('LINEBEFORE',(0,0),(0,0),3,TEAL),('LEFTPADDING',(0,0),(-1,-1),15),('RIGHTPADDING',(0,0),(-1,-1),15),('TOPPADDING',(0,0),(-1,-1),5),('BOTTOMPADDING',(0,0),(-1,-1),7)]))
    return t

def draw_vector_logo(canvas,x,y,width):
    """Draw this repository's M/C/Z SVG paths as native PDF vectors.

    The light logo contains a white background path; leave it out so the page
    color shows through the background and letter counters. No raster conversion.
    """
    root=etree.parse(str(ROOT/'src/assets/dnr-logo.svg')).getroot()
    vx,vy,vw,vh=map(float,root.get('viewBox').split());scale=width/vw
    canvas.saveState();canvas.translate(x,y+vh*scale);canvas.scale(scale,-scale);canvas.translate(-vx,-vy)
    for element in root:
        if element.get('fill','').lower()=='#ffffff':continue
        if etree.QName(element).localname!='path':raise ValueError('Unexpected logo element')
        tokens=re.findall(r'[A-Za-z]|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?',element.get('d',''))
        path=canvas.beginPath();index=0
        while index<len(tokens):
            command=tokens[index];index+=1
            count={'M':2,'C':6,'Z':0}.get(command)
            if count is None:raise ValueError('Unsupported logo path command: '+command)
            values=list(map(float,tokens[index:index+count]));index+=count
            if command=='M':path.moveTo(*values)
            elif command=='C':path.curveTo(*values)
            else:path.close()
        canvas.setFillColor(colors.HexColor(element.get('fill')))
        canvas.setStrokeColor(colors.HexColor(element.get('stroke',element.get('fill'))))
        canvas.setLineWidth(float(element.get('stroke-width','0')));canvas.setLineJoin(1)
        canvas.drawPath(path,stroke=1,fill=1,fillMode=0)
    canvas.restoreState()

class Cover(Flowable):
    def __init__(self):super().__init__();self.width=CW;self.height=685
    def draw(self):
        c=self.canv;c.saveState();c.setFillColor(colors.HexColor('#f6f7fb'));c.roundRect(0,0,CW,self.height,20,stroke=0,fill=1)
        c.setFillColor(PALE);c.circle(CW-32,self.height-64,126,stroke=0,fill=1)
        c.setStrokeColor(colors.HexColor('#b4d7d2'));c.setLineWidth(.8);c.circle(CW-68,self.height-14,92,stroke=1,fill=0)
        draw_vector_logo(c,30,592,205)
        c.setFillColor(TEAL);c.setFont('Bold',9);c.drawString(30,548,'MOED REFERENCE GUIDE')
        c.setFillColor(INK);c.setFont('Bold',39)
        for y,s in [(493,'Comprehensive'),(445,'User Manual')]:c.drawString(30,y,s)
        obj=p('Plan engagements. Keep relationship history.<br/>Coordinate follow-up. Manage communication.<br/>Protect and recover shared records.','intro');obj.wrap(CW-65,120);obj.drawOn(c,30,346)
        c.setFillColor(colors.white);c.roundRect(30,193,CW-60,119,12,stroke=0,fill=1)
        c.setFillColor(TEAL);c.setFont('Bold',8);c.drawString(47,287,'ONE CONNECTED WORKFLOW')
        for y,s in [(264,'Inquiry  >  Engagement  >  Presentations'),(242,'People  >  Chron and email  >  Follow-up'),(220,'Financial closeout  >  Relationship history')]:c.setFillColor(INK);c.setFont('Body',11);c.drawString(47,y,s)
        c.setFillColor(MUTED);c.setFont('Body',9);c.drawString(30,143,f'Application source {VERSION}  |  {EDITION_DATE}')
        c.drawString(30,124,'Reviewer, editor, and administrator reference')
        c.setFillColor(TEAL);c.setFont('Bold',10);c.drawString(30,68,'Open contents  >');c.linkRect('', 'contents',(30,58,166,84),relative=1,thickness=0)
        c.setFont('Body',8);c.setFillColor(MUTED);c.drawString(30,35,'Clickable chapters, topic finder, bookmarks, and related-topic navigation')
        c.restoreState()

class ManualDoc(BaseDocTemplate):
    def __init__(self,path,known=None):
        super().__init__(str(path),pagesize=(W,H),leftMargin=M,rightMargin=M,topMargin=50,bottomMargin=44,title='MOED Comprehensive User Manual',author='MOED / DNR',subject=f'Illustrated application reference, source {VERSION}',pageCompression=1)
        self.known=known or {};self.locations={};self.current_ch=0;self.page_chapters={};self.entries=[]
        self.page_layouts={};self.column_regions={};self.wide_blocks=[]
        def frames(columns):
            return [Frame(M+(COL+GUTTER)*index,44,COL if columns==2 else CW,H-94,id=f'column-{index+1}',leftPadding=0,rightPadding=0,topPadding=0,bottomPadding=0) for index in range(columns)]
        for name,columns in [('cover',1),('wide',1),('columns',2),('readme-index',1),('readme-wide',1),('readme-columns',2)]:
            self.addPageTemplates(PageTemplate(name,frames(columns),onPage=self.on_page,onPageEnd=self.on_end))
    def on_page(self,c,doc):
        layout=self.pageTemplate.id;self.page_layouts[doc.page]=layout
        if layout.startswith('readme'):
            c.saveState();c.setFillColor(colors.black);c.rect(0,0,W,H,stroke=0,fill=1);c.restoreState()
        c.bookmarkPage('page-'+str(doc.page));c.setTitle('MOED Comprehensive User Manual');c.setAuthor('MOED / DNR')
        if doc.page==1:c.bookmarkPage('cover');c.showOutline()
    def afterFlowable(self,f):
        if getattr(f,'chapter',None) is not None:self.current_ch=f.chapter
        if hasattr(f,'end_tag'):self.locations[f.end_tag]=self.page
        if getattr(f,'full_width',False):
            self.wide_blocks.append({'page':self.page,'label':f.width_label,'width':getattr(f,'_width',getattr(f,'width',0))})
        if hasattr(f,'dest'):
            self.canv.bookmarkHorizontalAbsolute(f.dest,self.frame._y+getattr(f,'height',getattr(f,'_height',0)),left=self.frame._x)
            self.canv.addOutlineEntry(f.outline_title,f.dest,level=f.outline_level,closed=f.outline_level==0)
            self.locations[f.dest]=self.page
            self.entries.append((f.outline_title,f.dest,self.page))
    def on_end(self,c,doc):
        self.page_chapters[doc.page]=self.current_ch
        if doc.page==1:return
        reverse=self.pageTemplate.id.startswith('readme')
        accent=colors.white if reverse else TEAL;muted=colors.white if reverse else MUTED
        c.saveState();c.setStrokeColor(colors.HexColor('#555555') if reverse else BORDER);c.setLineWidth(.5);c.line(M,H-33,W-M,H-33);c.line(M,32,W-M,32)
        if len(self.pageTemplate.frames)==2:
            top=max(frame._y1+frame._height for frame in self.pageTemplate.frames)
            self.column_regions[doc.page]={'top':H-top,'bottom':H-44}
            self.page_layouts[doc.page]=('readme-' if reverse else '')+('mixed' if top<H-50 else 'columns')
            c.line(W/2,49,W/2,top-4)
        c.setFillColor(accent);c.setFont('Bold',8);c.drawString(M,H-24,'MOED')
        c.setFillColor(muted);c.setFont('Body',7.5)
        label='Appendix A - README.md' if self.current_ch==14 else SHORT[self.current_ch-1] if self.current_ch else 'Comprehensive User Manual'
        c.drawString(M+38,H-24,label)
        c.setFillColor(accent);c.drawRightString(W-M,H-24,'Contents   |   Topic finder')
        c.linkRect('', 'contents',(W-M-114,H-29,W-M-61,H-17),thickness=0);c.linkRect('','topics',(W-M-56,H-29,W-M,H-17),thickness=0)
        c.setFont('Body',7.5);c.drawString(M,20,'< Previous');c.linkRect('','page-'+str(doc.page-1),(M,14,M+49,29),thickness=0)
        total=self.known.get('__total__',0)
        c.setFillColor(muted);c.drawCentredString(W/2,20,f'{doc.page}' +(f' / {total}' if total else ''))
        if self.current_ch:
            c.setFillColor(accent);c.drawString(M+70,20,'Appendix start' if self.current_ch==14 else 'Chapter start');c.linkRect('','appendix-readme' if self.current_ch==14 else 'chapter-'+str(self.current_ch),(M+70,14,M+135,29),thickness=0)
        if doc.page<total:
            c.setFillColor(accent);c.drawRightString(W-M,20,'Next >');c.linkRect('','page-'+str(doc.page+1),(W-M-33,14,W-M,29),thickness=0)
        c.restoreState()
    def afterDocument(self):self.locations['__total__']=self.page;self.canv.showOutline()

def table_from(e):
    rows=[]
    for tr in e.findall('.//tr'):
        rows.append([p(inline(cell),'table_cell') for cell in tr if cell.tag in ('td','th')])
    if not rows:return []
    cols=max(map(len,rows));widths=[CW/cols]*cols
    if cols==4:widths=[CW*.49]+[CW*.17]*3
    for row in rows:
        for index,cell in enumerate(row):
            if cell.minWidth()>widths[index]-16:raise RuntimeError('Table word exceeds full-width cell: '+cell.getPlainText())
    t=Table(rows,colWidths=widths,repeatRows=1,hAlign='LEFT')
    t.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('BACKGROUND',(0,0),(-1,0),PALE),('LINEBELOW',(0,0),(-1,-1),.45,BORDER),('LEFTPADDING',(0,0),(-1,-1),8),('RIGHTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8)]))
    t.full_width=True;t.width_label=' / '.join(cell.getPlainText() for cell in rows[0])
    return [Spacer(1,6),t,Spacer(1,12)]

def responsive_flow(flowables,reverse=False):
    """Give wide tables/long references a spanning frame, then resume columns."""
    columns='readme-columns' if reverse else 'columns';wide='readme-wide' if reverse else 'wide'
    wide_indices=[i for i,item in enumerate(flowables) if getattr(item,'full_width',False)]
    # A table immediately after the chapter introduction shares its first page.
    first_wide=wide_indices[0] if wide_indices and wide_indices[0]<=6 else None
    out=[NextPageTemplate(wide if first_wide is not None else columns),PageBreak()]
    for index,item in enumerate(flowables):
        if not getattr(item,'full_width',False):out.append(item);continue
        if index!=first_wide:
            lead=[]
            while out and isinstance(out[-1],Spacer):lead.insert(0,out.pop())
            if out and isinstance(out[-1],Paragraph) and getattr(out[-1].style,'keepWithNext',False):
                lead.insert(0,out.pop())
            out.extend([NextPageTemplate(wide),PageBreak(),*lead])
        out.append(item)
        out.append(FrameSplitter(columns,['column-1','column-2'],gap=16,required=120))
    return out

def nodes(e,ch):
    out=[];cls=e.get('class','')
    if any(x in cls for x in ['manual-chapter-heading','manual-open-area','manual-inline-link','manual-link-row','manual-current-role']):return []
    if e.tag in ('script','style'):return []
    if e.tag=='article' and 'manual-callout ' in cls:
        title=e.find('.//h3');paras=e.findall('.//p')
        if title is not None:
            t=card(text_of(title),[text_of(v) for v in paras],warning='manual-callout-warning' in cls)
            t.dest=f'topic-{ch}-{slug(text_of(title))}';t.outline_title=clean(text_of(title));t.outline_level=1
            return [Spacer(1,8),t,Spacer(1,14)]
    if e.tag in ('h3','h4'):
        title=text_of(e);out.append(heading(title,f'topic-{ch}-{slug(title)}',1,'h3' if e.tag=='h3' else 'h4'));return out
    if e.tag=='summary':return [heading(text_of(e).rstrip('+').strip(),f'topic-{ch}-{slug(text_of(e).rstrip("+"))}',1,'h4')]
    if e.tag=='p':
        if not (e.text or '').strip() and len(e)==1 and e[0].tag=='a' and 'manual-inline-link' in e[0].get('class',''):return []
        if 'manual-example' in cls:
            code=e.find('code');return [p('Example: '+inline(code))] if code is not None else [p(inline(e))]
        if text_of(e):out.append(p(inline(e)))
        return out
    if e.tag in ('ul','ol'):
        for n,li in enumerate(e.findall('./li'),1):
            if e.tag=='ol':
                pieces=li.findall('./section')
                content=inline(pieces[0]) if pieces else inline(li)
                # HTML block boundaries need explicit spaces in a PDF paragraph.
                content=content.replace('</b>', '</b> ')
                out.append(p(f'<font color="#0f766e"><b>{n:02}</b></font>   '+content,'step'))
            else:out.append(p('<font color="#0f766e"><b>•</b></font>   '+inline(li),'step'))
        return out
    if e.tag=='table':return table_from(e)
    if e.tag=='dl':
        rows=[]
        for d in e:
            dt=d.find('dt');dd=d.find('dd')
            if dt is not None and dd is not None:rows.append([p('<b>'+inline(dt)+'</b>','cell'),p(inline(dd),'cell')])
        if rows:
            t=Table(rows,colWidths=[90,COL-90]);t.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('LINEABOVE',(0,0),(-1,-1),.4,BORDER),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8)]));out.extend([t,Spacer(1,10)])
        return out
    if 'manual-icon-legend' in cls:
        for item in e:
            strong=item.find('strong');small=item.find('small')
            if strong is not None and small is not None:out.append(p('<b>'+inline(strong)+'</b> - '+inline(small)))
        return out
    for child in e:out+=nodes(child,ch)
    return out

SUPPLEMENTS={
 1:[('Save, cancel, and recover a draft',['Required fields are marked in the forms. Correct the listed errors and save again if validation fails. Cancel returns without saving the current form.','When you create a related organization or contact from an inquiry/contact form, MOED carries the draft back to the original form. Confirm the selected relationship after returning.','If another person changes a record while you are editing, MOED may report a conflict. Preserve your unsaved text, reload the latest record, and reapply only the changes that still belong. A reload is also the first step after an expired request.','Keyboard users can use the Skip to main content link, Tab/Shift+Tab, and the visible focus indication. Reduce-motion preferences suppress attention animations while retaining due-state color cues.'])],
 5:[('Presentation reference and file history',['A complete presentation needs its topic, date, time, selected speaker, duration, and expected attendance. Its date must be inside the engagement range before the event can be confirmed.','PDF Speaker Notes displays upload metadata, including who uploaded the current file and when, where available. Old files without stored uploader metadata can show an unavailable uploader.','Speaker Notes QR links open the PDF directly without signing in. Replacement keeps the public code; removal makes the notes unavailable. Website/resource codes and speaker-note downloads are recorded as visitor activity; viewing internal QR sheets does not add visits.','Archive and restore presentations separately from the engagement. A restored presentation must fit the current dates. Changing its speaker changes the current resource set; previous speaker codes remain part of historical statistics.']),('Readiness, closeout, and lifecycle effects',['Use the readiness panel to find missing presentations, contact details, or planning information. Resolve the source record and revisit the panel.','Canceling an engagement requires a reason and cancels its open event tasks. A task canceled for convenience still fails a required Completed prerequisite for financial closeout.','Changing event dates updates generated active task dates unless a date was manually overridden. Postponed/canceled originals can link to a replacement from the same organization; circular reschedule links are rejected.'])],
 6:[('Birthdays and organization affiliations',['A contact can also store an optional birthday. Birthday-enabled calendar subscriptions create yearly all-day entries; they do not expose the birth year or internal contact notes.','Additional affiliations are independent relationships, each with its own role or title. A contact can remain associated with another organization when one organization is removed. Event-specific assignments belong to each engagement.'])],
 8:[('Email delivery states and retries',['Queued messages can be Pending or Processing before becoming Sent, Failed, or partially delivered across recipients. Open the delivery record to inspect individual recipient outcomes and timestamps.','Retry Failed Deliveries retries the failures rather than re-sending successful recipients. Correct a bad destination before retrying. Inquiry delivery retries are available while the inquiry is active.','After booking conversion, replies to earlier inquiry email route into the resulting engagement. The original inquiry retains the pre-booking record and any tasks not moved.','The Inbox filing panel saves an email to existing destinations. Create a new booking request from New Inquiry in Booking Pipeline when needed, then return to the Inbox and review the appropriate destinations.'])],
 9:[('Choose the content of each private feed',['Each new subscription has a Device or Service label and one or more content options: Events, Presentations, My Active Work, All Active Work, and Birthdays (from Contacts). The defaults are Events, Presentations, and Birthdays.','My Active Work includes assigned work; All Active Work includes everyone and unassigned work. Active work appears on its due date. A subscription can therefore reveal task titles and schedule context: share it only with the intended device or service.','The private URL is shown only once. Save it immediately or create a new subscription if it is lost. Revoke one link without changing others, and purge revoked token records when no longer needed.','The in-app month selector and a private feed are separate controls. Changing the month selector does not change the saved subscription content.']),('Correct a map location',['Use Edit location to correct the written event address. If the address is right but the lookup pin is wrong, open the pin editor, choose the intended coordinates, and save the manual confirmation.','A manual override is tied to the address it confirms. Check it again after changing the address. Clear the override to return to normal address lookup. The map/list can still show an event awaiting lookup or without enough address information.'])],
 10:[('Change the recovery email securely',['Use the separate Change Recovery Email panel. Enter the new address, current password, and a fresh authenticator code when 2FA is enabled, then select Verify New Email Address.','The current address stays in use while the new address is pending. Follow the verification message sent to the new address to finish. Successful verification signs out all sessions and pauses the daily digest. Sign in again and review notification preferences.','Cancel Pending Email Change abandons a pending request. Resend email verification requests a new message while preserving the profile draft and chosen photo. Editing the ordinary profile form does not directly replace the recovery destination.'])],
 12:[('User lifecycle effects',['Deactivating a user revokes their sessions and private calendar links and leaves their assigned work unassigned. Reactivating them does not recreate subscriptions or restore task ownership; review both explicitly.','An administrator can issue a temporary password or reset another user\'s authenticator after fresh confirmation. The old password, authenticator secret, and recovery codes cannot be displayed.','Active accounts must be deactivated before deletion. Pending invitations may be deleted directly. Account removal is distinct from archiving operational records.']),('Deployment notices and recovery boundaries',['When a deployment is announced, finish or preserve your current work and follow the on-screen preparation/countdown message. Wait for service to return before retrying a save.','The PDF documents browser operations. Deployment, secret management, database restoration, migration repair, and infrastructure configuration belong to the deployment operator. Use the Exact database restore runbook in README.md and docs/release-workflow.md for those procedures.','The internal operations/readiness views help diagnose service state. They are not a substitute for a verified recovery backup or a successful restore check.'])],
}

def corrected_root(ch):
    e=LH.fragment_fromstring(CHAPTERS[ch-1]['html'],create_parent='div')
    # The application has features added after the sidebar guide text. Keep the PDF accurate.
    if ch==9:
        for title in e.findall('.//h3'):
            if text_of(title)=='Private Calendar':
                ps=title.getparent().findall('./p')
                ps[0].text='Create a separate subscription for each device or service. Choose Events, Presentations, My Active Work, All Active Work, and/or Birthdays (from Contacts). Copy the private URL when it is shown; it is shown only once.'
                ps[1].text='Revoke one link without affecting the others; revoked records can be purged. A link grants access to its selected feed content, including work when selected. It does not grant application access to internal Chron or financial records.'
        for table in e.findall('.//table'):
            if 'My Tasks' in text_of(table):
                body=table.find('tbody')
                body.insert(3,LH.fragment_fromstring('<tr><td><strong>Birthdays</strong></td><td>Annual birthday reminders from active contacts.</td><td>Birthday styling; open the contact for details.</td></tr>'))
                for row in body.findall('tr'):
                    cells=row.findall('td')
                    if cells and text_of(cells[0])=='Everything':cells[1].text='Events, all active due-dated tasks, and contact birthdays.'
        for para in e.findall('.//p'):
            if 'Events plus all' in text_of(para):para.text='Everything includes events, due-dated active tasks, and contact birthdays.'
    if ch==10:
        for title in e.findall('.//h3'):
            if text_of(title)=='Profile and Notifications':
                ps=title.getparent().findall('./p');ps[0].text='Maintain your name, phone, optional profile picture, and notification schedule in My Profile. Use Change Recovery Email for a protected email change: the current address remains active until the new address is verified. Verification signs out existing sessions and pauses the daily digest. Resending verification preserves your draft and chosen photo.'
    return e

# Features added after the original sidebar guide's chapter text.
SUPPLEMENTS[9].append(('Birthdays and the mobile daily agenda',[
    'The month selector includes Birthdays. Everything includes events, active due-dated work, and birthdays. Enter contact birthdays as MM/DD; a birth year is not collected. February 29 birthdays recur on the last day of February in non-leap years.',
    'On a phone-sized display the calendar becomes a daily agenda. Use the previous/next day controls or Today, keep the desired content selector, and open the linked record. Desktop uses the month grid. The agenda and month view preserve the chosen content scope.'
]))
SUPPLEMENTS[5].append(('Reset presentation statistics',[
    'Administrators can choose Reset Presentation Statistics from a presentation card. Fresh administrator confirmation is required before Reset Statistics to Zero.',
    'The reset permanently removes every recorded link visit for that presentation across all dates, including disabled and previous-speaker links. It preserves QR codes, destinations, uploaded notes, and presentation details. Other presentations are unaffected, and future visits start counting from zero.'
]))
# State the counting boundary precisely: direct asset requests are not link visits.
SUPPLEMENTS[5][0][1][2]='Speaker Notes QR links open the PDF directly without signing in. Replacement keeps the code; removal makes the notes unavailable. A visit through the notes short link counts before the PDF opens, including cached delivery. Opening a copied direct PDF URL does not count. Internal QR sheets do not add visits.'
SUPPLEMENTS[12].append(('Reset and retire an account',[
    'Unlock sensitive actions on Users before selecting a reset or lifecycle control. Reset User Password asks for a new temporary password and confirmation. The user must replace it at sign-in.',
    'Reset 2FA uses the explicit RESET 2FA confirmation phrase. Resetting another user\'s factor is an account recovery action; the old secret and recovery codes remain unavailable.',
    'Review the deactivation confirmation before proceeding. To delete an invited or inactive account, use its Delete action and the requested confirmation phrase. Do not use deletion as the ordinary way to suspend access.'
]))
SUPPLEMENTS[8].append(('Archive and restore Chron in batches',[
    'Open the parent record\'s edit/Chron controls to archive an obsolete entry. Archive preserves history; administrator-only deletion removes it permanently after fresh confirmation.',
    'Open Restore Archived Chron Log Entries, select the entries to return, and choose Restore Selected. The parent must be active. Restored entries retain their historical authorship and dates.'
]))
SUPPLEMENTS[5].append(('Restore archived presentations',[
    'Open the engagement editor and choose Restore Archived Presentations. Select the desired presentations, correct their dates or times when needed, and choose Restore Selected.',
    'Restored presentation dates must fit the event\'s current range. The active engagement and selected speaker must be valid. Administrators can delete an archived presentation permanently from the restore view after fresh confirmation.'
]))

def related(ch,known):
    links=[]
    for n in RELATED[ch]:
        key='chapter-'+str(n);page=known.get(key,'...');links.append(f'<link href="#{key}" color="#2457d6">{esc(SHORT[n-1])} (p. {page})</link>')
    return p('<b>Related chapters:</b> '+'  /  '.join(links),'small')

TOPICS=[
 ('Account activation and invitations','shot-invite'),('Account recovery','chapter-10'),('Administrator confirmation','shot-elevation'),('Archive, restore, and permanent deletion','topic-5-archive-and-delete-carefully'),('Audit log and retention','shot-audit'),('Backup and restoration','shot-backup'),('Birthdays','topic-9-choose-the-content-of-each-private-feed'),('Booking conversion','shot-conversion'),('Booking stages and board filters','shot-pipeline'),('Calendar content selectors','shot-calendar'),('Calendar subscriptions and revocation','shot-subscription'),('Caller and generated task ownership','chapter-7'),('Change recovery email','topic-10-change-the-recovery-email-securely'),('Chron log entries','shot-chron'),('Closeout prerequisites','shot-closeout'),('Confirmation vs. lifecycle','shot-lifecycle'),('Contact affiliations and event roles','shot-contact-form'),('Contact photographs','shot-contact-form'),('Countries, addresses, states, and provinces','shot-organization-form'),('Daily digest','shot-profile'),('Dashboard and readiness','shot-dashboard'),('Dates, rescheduling, and task offsets','chapter-7'),('Decline and reopen an inquiry','shot-inquiry'),('Deployment notices','topic-12-deployment-notices-and-recovery-boundaries'),('Duplicate a task','shot-duplicate-task'),('Email delivery and retry','topic-8-email-delivery-states-and-retries'),('Email recipients and templates','shot-email'),('Email routing and signed markers','shot-inbound-message'),('Event contacts','shot-event-contacts'),('Event logistics and planning estimates','shot-logistics'),('Financial drafts and final reports','shot-closeout'),('Financial history by organization','shot-organization-financial'),('Inbound mail triage','shot-inbox'),('Inquiry correspondence','shot-inquiry-email'),('Keyboard navigation','chapter-1'),('Map and missing addresses','shot-map'),('Manual map pin','shot-map-pin'),('Mattermost commands and account linking','chapter-11'),('Mattermost message actions','topic-11-turn-a-mattermost-post-into-moed-work'),('Mobile sidebar and theme','shot-mobile'),('Next action on an inquiry','shot-inquiry-action'),('Operations and readiness','shot-operations'),('Passwords and 2FA','shot-security'),('PDF speaker notes','shot-presentation-form'),('Presentation export: text, Markdown, PDF','shot-presentations'),('Presentation schedule and attendance','shot-presentation-form'),('QR code resources and downloads','shot-presentations'),('QR code statistics and link controls','shot-statistics'),('Recovery codes','chapter-10'),('Roles: reviewer, editor, administrator','chapter-2'),('Search and records per page','chapter-1'),('Speaker biography and links','shot-speaker'),('Speaker custom resource links','shot-speaker-links'),('Standard event checklists','shot-standard-tasks'),('Task status, ownership, priority, and due date','shot-task-form'),('Troubleshooting','chapter-13'),('User deactivation and deletion','topic-12-user-lifecycle-effects'),('Work queue and reminders','shot-tasks')]

def screenshot_page(s,number,known):
    ch=s['chapter'];out=[NextPageTemplate('wide'),PageBreak(),p(f'CHAPTER {ch:02}  /  ILLUSTRATED WALKTHROUGH','kicker'),heading(s['title'],'shot-'+s['id'],1,'h3')]
    split=(len(s['steps'])+1)//2;columns=[[],[]]
    for n,step in enumerate(s['steps'],1):columns[0 if n<=split else 1].append(p(f'<font color="#0f766e"><b>{n:02}</b></font>   '+esc(step),'step'))
    if s.get('note'):columns[1].append(p('<b>Screenshot context:</b> '+esc(s['note']),'small'))
    columns[1].append(related(ch,known))
    instructions=Table([[columns[0],'',columns[1]]],colWidths=[COL,GUTTER,COL],hAlign='LEFT')
    instructions.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),0),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),0),('LINEABOVE',(0,0),(-1,-1),.5,BORDER)]))
    _,instruction_height=instructions.wrap(CW,H)
    file=DATA/s['file'];im=PILImage.open(file);iw,ih=im.size;scale=min(CW/iw,min(430,585-instruction_height)/ih)
    out.append(Image(str(file),width=iw*scale,height=ih*scale,hAlign='LEFT'))
    out.append(Spacer(1,5))
    out.append(p(f'Figure {number:02}. {esc(s["title"])}. Actual preview screen; fictional records.','caption'))
    instructions.end_tag='end-shot-'+s['id'];out.append(instructions);return out

def readme_inline(e):
    """Retain README wording and link destinations without external PDF actions."""
    value=esc(e.text or '')
    for child in e:
        inner=readme_inline(child)
        if child.tag in ('strong','b'):value+='<b>'+inner+'</b>'
        elif child.tag in ('em','i'):value+='<i>'+inner+'</i>'
        elif child.tag=='code':value+='<font color="#ffffff" size="8.2">'+inner+'</font>'
        elif child.tag=='br':value+='<br/>'
        elif child.tag=='a':
            value+=inner
            href=child.get('href','')
            if href and href!=child.text_content():value+=' <font color="#667085">('+esc(href)+')</font>'
        else:value+=inner
        value+=esc(child.tail or '')
    return value

def readme_nodes(e):
    if e.tag in ('h1','h2','h3','h4','h5','h6'):
        return [heading(text_of(e),e.get('data-pdf-id'),1,'h4' if e.tag in ('h4','h5','h6') else 'h3',reverse=True)]
    if e.tag=='p':return [rp(readme_inline(e))]
    if e.tag=='pre':
        return [Preformatted(clean(e.text_content()).rstrip('\n'),REVERSE['code'],maxLineLength=54,splitChars=' ')]
    if e.tag in ('ul','ol'):
        out=[]
        for number,li in enumerate(e.findall('./li'),int(e.get('start','1'))):
            marker=f'{number:02}' if e.tag=='ol' else '•'
            prefix=f'<font color="#0f766e"><b>{marker}</b></font>   '
            block_tags={'p','ul','ol','pre','blockquote'}
            if not any(c.tag in block_tags for c in li):out.append(rp(prefix+readme_inline(li),'step'))
            else:
                first=True
                if (li.text or '').strip():out.append(rp(prefix+esc(li.text),'step'));first=False
                for child in li:
                    if child.tag=='p' and first:
                        item=rp(prefix+readme_inline(child),'step')
                        if len(child)==1 and child[0].tag=='strong' and text_of(child)==text_of(child[0]):item.keepWithNext=True
                        out.append(item);first=False
                    else:out+=readme_nodes(child)
        return out
    if e.tag=='table':return table_from(e)
    out=[]
    for child in e:out+=readme_nodes(child)
    return out

def readme_appendix(known):
    out=[NextPageTemplate('readme-index'),PageBreak(),rp('APPENDIX A  /  CURRENT REPOSITORY README','kicker'),heading('README.md','appendix-readme',0,'h2',14,reverse=True),rp(f'Complete README for application source {VERSION}','intro'),rp('The following pages reproduce the full current README, including deployment procedures and command examples. Long code lines wrap to fit the page. The original, unmodified README.md is also embedded as a PDF attachment for copying commands exactly.'),rp(f'Source: README.md · {len(README_BYTES):,} bytes · {EDITION_DATE}. The build records its SHA-256 checksum. Select a section below, or expand Appendix A in the PDF bookmarks.','small')]
    columns=[]
    for group in (README_HEADINGS[:19],README_HEADINGS[19:]):
        columns.append([rp(f'<link href="#{item["key"]}" color="#ffffff">{esc(item["title"])}</link> - p. {known.get(item["key"],"...")}','small') for item in group])
    toc=Table([[columns[0],columns[1]]],colWidths=[CW/2,CW/2],hAlign='LEFT')
    toc.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),18)]))
    out.append(toc)
    out+=responsive_flow(readme_nodes(README_ROOT),reverse=True)
    return out

def story(known):
    out=[Cover(),NextPageTemplate('wide'),PageBreak(),heading('Contents','contents',0,'h2',0),p('Select a chapter or use the PDF bookmarks panel. Printed page numbers are the same as the page numbers in your PDF reader.','intro')]
    contents=[[],[]]
    for n,c in enumerate(CHAPTERS,1):
        key='chapter-'+str(n);row=[[p(f'<font color="#0f766e"><b>{n:02}</b></font>','toc'),p(f'<link href="#{key}" color="#172033">{esc(c["title"])}</link>','toc'),p(f'<link href="#{key}" color="#0f766e">{known.get(key,"...")}</link>','toc')]]
        t=Table(row,colWidths=[28,COL-60,32]);t.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('LINEBELOW',(0,0),(-1,-1),.4,BORDER),('TOPPADDING',(0,0),(-1,-1),10),('BOTTOMPADDING',(0,0),(-1,-1),10)]));contents[0 if n<=7 else 1].append(t)
    toc=Table([[contents[0],'',contents[1]]],colWidths=[COL,GUTTER,COL]);toc.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),0)]));out.append(toc)
    out.extend([Spacer(1,16),p(f'<link href="#topics" color="#2457d6"><b>Alphabetical topic finder</b> - p. {known.get("topics","...")}</link>')])
    out.append(p(f'<link href="#appendix-readme" color="#2457d6"><b>Appendix A: complete current README.md</b> - p. {known.get("appendix-readme","...")}</link>'))
    out.extend([NextPageTemplate('columns'),PageBreak(),heading('How to use this manual','how-to-use',0,'h2',0),p('A complete reference with illustrated walkthroughs','intro'),card('Navigate in four ways',['Use the clickable contents for chapters; use the bookmarks panel for sections and screenshots; follow Related chapters links for connected work; or use the alphabetical topic finder. Every page has Contents, Topic finder, Previous, Next, and Chapter start links.']),Spacer(1,16),p('Scope and edition','h3'),p(f'This manual describes the MOED browser application at source version {VERSION}, updated on {EDITION_DATE}. It covers reviewer, editor, and administrator workflows and the configured Mattermost integration. Deployment-specific settings may change names, reminder windows, available integrations, or branding.'),p('The 13 chapters follow the sidebar User Manual. Each begins with the reference guidance and continues with illustrated walkthroughs. Section and figure headings also appear as PDF bookmarks.'),p('Screenshots and example records','h3'),p('Screenshots were captured from the current source in an isolated local preview. Cedar Grove Community, Jordan Parker, Alex Morgan, and their event/inquiry are fictional training examples. A few existing preview records and the preview account name may appear in directory or account views. No production messages were sent and no invitations were submitted.'),p('The preview has no connected Mattermost service and disables mail delivery. Those capabilities are documented from the application guide and source; screenshots label the configuration limitation. Empty financial/statistics views represent a new training record, not a claim that the feature is unavailable.'),p('Screenshots show selected viewports and form sections. Scroll in the application to continue longer forms. Zoom into the PDF for fine control labels. Most screenshots use the light theme; dark theme and mobile navigation are illustrated separately.'),p('Meaning of access labels','h3'),p('<b>Reviewer:</b> read records, export, inspect statistics, and manage the personal account.<br/><b>Editor:</b> additionally create, edit, archive/restore, manage work, and correspond.<br/><b>Administrator:</b> additionally govern users, audit, backups, and permanent deletion.'),related(1,known)])
    figure=0
    for ch,c in enumerate(CHAPTERS,1):
        root=corrected_root(ch)
        chapter_flow=[p(f'CHAPTER {ch:02}','kicker'),heading(c['title'],'chapter-'+str(ch),0,'h2',ch)]
        intro=root.xpath('.//*[contains(@class,"manual-chapter-heading")]/p')
        if intro:chapter_flow.append(p(inline(intro[0]),'intro'))
        chapter_flow.append(related(ch,known));chapter_flow+=nodes(root,ch)
        for title,paras in SUPPLEMENTS.get(ch,[]):
            chapter_flow.append(heading(title,f'topic-{ch}-{slug(title)}',1,'h3'))
            chapter_flow.extend(p(esc(v)) for v in paras)
        out+=responsive_flow(chapter_flow)
        for s in SHOTS:
            if s['chapter']==ch:
                if s.get('error') or not s.get('file') or not (DATA/s['file']).exists():raise RuntimeError('Incomplete screenshot: '+s['id'])
                figure+=1;out+=screenshot_page(s,figure,known)
    out.extend([NextPageTemplate('columns'),PageBreak(),heading('Topic finder','topics',0,'h2',0),p('Select a topic to open the relevant procedure. Use the PDF bookmarks for the full section and screenshot outline.','intro')])
    for title,key in sorted(TOPICS,key=lambda x:x[0].lower()):
        if known and key not in known:raise RuntimeError('Unknown topic target '+key)
        out.append(p(f'<link href="#{key}" color="#2457d6">{esc(title)}</link> <font color="#667085">- p. {known.get(key,"...")}</font>','small'))
    out.extend([Spacer(1,14),heading('Edition and source notes','edition-notes',0,'h3'),p(f'Application source: MOED / DNR {VERSION}. Guide updated: {EDITION_DATE}. Inbox screenshots were refreshed for this edition; other screenshots were captured on September 9, 2026. Source basis: src/help.php, application page/forms/controllers, and the Mattermost integration guide.'),p('The PDF expands the online guide with current private-calendar content options, birthdays, recovery-email verification, map-pin correction, and additional form walkthroughs. Infrastructure-only procedures are referenced at the point where a deployment operator is required.'),p('Document build and screenshots: scripts/manual/ and docs/user-manual/. Rebuild with the included instructions when the application changes. Navigation is internal to this PDF; links do not depend on the sample preview being available.'),p('<link href="#contents" color="#2457d6">Return to contents</link>')])
    out+=readme_appendix(known)
    return out

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--install',action='store_true',help='Also copy the checked output into the application download directory');args=parser.parse_args()
    OUT.parent.mkdir(parents=True,exist_ok=True);tmp=ROOT/'tmp/pdfs/manual-layout.pdf';tmp.parent.mkdir(parents=True,exist_ok=True)
    known={}
    for attempt in range(4):
        doc=ManualDoc(tmp,known);doc.build(story(known));current={**doc.locations,'__total__':doc.page}
        if current==known:break
        known=current
    else:raise RuntimeError('Page numbering did not stabilize')
    writer=PdfWriter(clone_from=tmp)
    writer.add_attachment('README.md',README_BYTES)
    with OUT.open('wb') as stream:writer.write(stream)
    reader=PdfReader(OUT)
    for shot in SHOTS:
        if known['shot-'+shot['id']]!=known['end-shot-'+shot['id']]:raise RuntimeError('Screenshot walkthrough spills onto a second page: '+shot['id'])
    links=sum(len(p.get('/Annots',[])) for p in reader.pages)
    report={'version':VERSION,'pages':len(reader.pages),'screenshots':len(SHOTS),'link_annotations':links,'destinations':known,'outline_entries':len(doc.entries),'sources':['src/help.php','src/view_calendar.php','src/profile.php','src/map_pin.php','src/templates/presentation_form.php']}
    report['page_layouts']=doc.page_layouts
    report['column_regions']=doc.column_regions
    report['full_width_blocks']=doc.wide_blocks
    report['automatic_word_breaks']=0
    report['cover_logo']={'source':'src/assets/dnr-logo.svg','rendering':'native PDF vector paths','white_backdrop':'omitted'}
    report['readme_appendix']={'source':'README.md','sha256':README['sha256'],'bytes':len(README_BYTES),'start_page':known['appendix-readme'],'sections':len(README_HEADINGS),'attachment':'README.md'}
    (DATA/'build-report.json').write_text(json.dumps(report,indent=2)+'\n')
    if args.install:
        public=ROOT/'src/assets/docs'/OUT.name;public.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(OUT,public)
    print(json.dumps({k:v for k,v in report.items() if k not in ['destinations','sources','page_layouts','column_regions']},indent=2))
    print(OUT)
if __name__=='__main__':main()
