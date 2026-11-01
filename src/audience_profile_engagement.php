<?php
declare(strict_types=1);

trait AudienceProfileEngagement
{
    public function personInsights(int $person):array
    {
        $this->person($person);
        $rows=$this->analyticsRecipients('1970-01-01 00:00:00',gmdate('Y-m-d H:i:s',time()+1),'filtered',['contact_id'=>$person]);
        $groups=[];foreach($rows as$row)$groups[(int)$row['campaign_id']][]=$row;
        $campaigns=[];
        if($groups)foreach($this->all('SELECT id,name FROM marketing_campaigns WHERE audience_id=? AND id IN ('.implode(',',array_fill(0,count($groups),'?')).') ORDER BY sent_at DESC,id DESC',[$this->audienceId,...array_keys($groups)])as$c)$campaigns[]=$c+['metrics'=>$this->analyticsTotals($groups[(int)$c['id']])];
        return ['totals'=>$this->analyticsTotals($rows),'campaigns'=>$campaigns];
    }

    /** Metadata only; Inbox content and bodies never enter the profile timeline. */
    public function personActivity(int $person,string $type,int $days,int $year,int $page,bool $includeInbox,int $size=25):array
    {
        $this->person($person);$size=audiencePageSize(['per_page'=>$size]);$page=max(1,$page);
        $union="SELECT CONCAT('contact-',e.id) event_id,e.event_type,e.actor_label,e.details_json,e.created_at FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=? AND c.id=?
            UNION ALL SELECT CONCAT('accepted-',r.id),'email_accepted','Email delivery',JSON_OBJECT('campaign',m.name,'campaign_id',m.id),r.accepted_at FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id JOIN marketing_campaigns m ON m.id=d.campaign_id AND m.audience_id=d.audience_id WHERE r.audience_id=? AND r.contact_id=? AND r.state='accepted'
            UNION ALL SELECT CONCAT('message-',e.id),CONCAT('email_',e.event_type),'Email activity',JSON_OBJECT('campaign',m.name,'campaign_id',m.id,'bot',(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')='true'),'interaction_location',JSON_EXTRACT(e.details_json,'$.interaction_location')),e.event_at FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id AND r.audience_id=e.audience_id JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id JOIN marketing_campaigns m ON m.id=d.campaign_id AND m.audience_id=d.audience_id WHERE r.audience_id=? AND r.contact_id=? AND e.event_type<>'send'";
        $params=[$this->audienceId,$person,$this->audienceId,$person,$this->audienceId,$person];
        if($includeInbox){
            $union.=" UNION ALL SELECT CONCAT('inbox-',m.id),CONCAT('inbox_',m.direction),'Inbox',JSON_OBJECT('subject',t.subject,'thread_id',t.id),m.occurred_at FROM marketing_inbox_messages m JOIN marketing_inbox_threads t ON t.id=m.thread_id AND t.audience_id=m.audience_id WHERE t.audience_id=? AND t.contact_id=? AND t.status<>'trash'";
            array_push($params,$this->audienceId,$person);
        }
        $years=array_map('intval',array_column($this->all("SELECT DISTINCT YEAR(created_at) year FROM ($union) activity ORDER BY year DESC",$params),'year'));
        $where='1=1';$filter=[];
        if($type!==''){$where.=' AND event_type=?';$filter[]=$type;}
        if($year){$where.=' AND created_at>=? AND created_at<?';$filter[]=$year.'-01-01 00:00:00';$filter[]=($year+1).'-01-01 00:00:00';}
        elseif($days){$where.=' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY)';$filter[]=$days;}
        $allParams=[...$params,...$filter];
        $total=(int)$this->one("SELECT COUNT(*) n FROM ($union) activity WHERE $where",$allParams)['n'];
        $page=min($page,max(1,(int)ceil($total/$size)));
        return ['years'=>$years,'total'=>$total,'page'=>$page,'rows'=>$this->all("SELECT * FROM ($union) activity WHERE $where ORDER BY created_at DESC,event_id DESC LIMIT ? OFFSET ?",[...$allParams,$size,($page-1)*$size])];
    }
}
