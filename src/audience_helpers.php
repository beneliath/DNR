<?php
declare(strict_types=1);
require_once __DIR__.'/audience_pagination.php';

const AUDIENCE_STATUSES = ['non-subscribed', 'subscribed', 'unsubscribed', 'pending', 'cleaned'];

/** One address per line; validate the complete list before queuing any changes. */
function audienceUnsubscribeAddresses(string $input): array
{
    $addresses=[];
    foreach(preg_split('/\R/u',$input)?:[]as$index=>$line){
        $email=strtolower(trim($line));if($email==='')continue;
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address on line '.($index+1).'.');
        $addresses[$email]=$email;
        if(count($addresses)>5000)throw new InvalidArgumentException('Unsubscribe up to 5,000 email addresses at a time.');
    }
    if(!$addresses)throw new InvalidArgumentException('Enter at least one email address.');
    return array_values($addresses);
}

function audienceText(array $input, string $key, int $max = 100): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . '.');
    }
    $value = trim($value);
    if (mb_strlen($value) > $max || str_contains($value, "\0")) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . ' is too long or contains invalid characters.');
    }
    return $value;
}

function audienceContactInput(array $input): array
{
    $email = strtolower(audienceText($input, 'email', 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $status = audienceText($input, 'email_status');
    if (!in_array($status, AUDIENCE_STATUSES, true)) {
        throw new InvalidArgumentException('Choose a valid subscription status.');
    }
    $tags = [];
    foreach (explode(',', audienceText($input, 'tags', 2000)) as $name) {
        $name = trim($name);
        if ($name === '') continue;
        if (mb_strlen($name) > 100) throw new InvalidArgumentException('Tag names must be 100 characters or fewer.');
        $tags[mb_strtolower($name)] = $name;
    }
    if (count($tags) > 20) throw new InvalidArgumentException('Choose up to 20 tags per connection.');
    return [
        'email' => $email, 'first_name' => audienceText($input, 'first_name'),
        'last_name' => audienceText($input, 'last_name'), 'email_status' => $status,
        'source' => audienceText($input, 'source') ?: 'Manual entry',
        'notes' => audienceText($input, 'notes', 10000), 'tags' => array_values($tags),
        'consent_note' => audienceText($input, 'consent_note', 1000),
    ];
}

function audienceRequireConsent(array $data, ?array $previous): void
{
    $newSubscription = $previous === null || $previous['email_status'] !== 'subscribed'
        || strcasecmp($previous['email'], $data['email']) !== 0;
    if ($data['email_status'] === 'subscribed' && $newSubscription && $data['consent_note'] === '') {
        throw new InvalidArgumentException('Record when and how this connection gave permission before marking them subscribed.');
    }
    // Cleaned addresses remain suppressed until a new address is supplied.
    if ($previous !== null && $previous['email_status'] === 'cleaned'
        && $data['email_status'] !== 'cleaned' && strcasecmp($previous['email'], $data['email']) === 0) {
        throw new InvalidArgumentException('A cleaned email address remains suppressed. Supply a corrected address and fresh permission to subscribe.');
    }
}

function audienceFilters(array $input): array
{
    $status = audienceText($input, 'status');
    if ($status !== '' && !in_array($status, AUDIENCE_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown subscription status.');
    }
    return ['q' => audienceText($input, 'q', 254), 'status' => $status,
        'tag' => audienceText($input, 'tag'), 'source' => audienceText($input, 'source'),
        'archived' => ($input['archived'] ?? '') === '1' ? '1' : '0'];
}

/** Only fixed SQL fragments enter the query; every filter value is bound. */
function audienceWhere(array $filters): array
{
    $sql = 'c.is_archived = ?';
    $params = [(int) $filters['archived']];
    if ($filters['q'] !== '') {
        $sql .= " AND (LOCATE(?, c.email) > 0 OR LOCATE(?, CONCAT(c.first_name, ' ', c.last_name)) > 0)";
        array_push($params, $filters['q'], $filters['q']);
    }
    foreach (['status' => 'email_status', 'source' => 'source'] as $filter => $column) {
        if ($filters[$filter] !== '') {
            $sql .= ' AND c.' . $column . ' = ?';
            $params[] = $filters[$filter];
        }
    }
    if ($filters['tag'] !== '') {
        $sql .= ' AND EXISTS (SELECT 1 FROM marketing_contact_tags ct JOIN marketing_tags t ON t.id = ct.tag_id WHERE ct.contact_id = c.id AND t.name = ?)';
        $params[] = $filters['tag'];
    }
    return [$sql, $params];
}

function audienceEvent(mysqli $db, int $id, int $actor, string $type, array $details): void
{
    $db->execute_query('INSERT INTO marketing_contact_events (contact_id, actor_user_id, event_type, details_json) VALUES (?, ?, ?, ?)',
        [$id, $actor, $type, json_encode($details, JSON_THROW_ON_ERROR)]);
}

function audienceSaveContact(mysqli $db, array $input, int $id, int $version, int $actor): int
{
    $data = audienceContactInput($input);
    $db->begin_transaction();
    try {
        $previous = $id > 0 ? $db->execute_query('SELECT * FROM marketing_contacts WHERE id = ? FOR UPDATE', [$id])->fetch_assoc() : null;
        if ($id > 0 && (!$previous || (int) $previous['version'] !== $version)) {
            throw new InvalidArgumentException('This connection changed or is unavailable. Reload before editing.');
        }
        audienceRequireConsent($data, $previous);
        $values = [$data['email'], $data['first_name'], $data['last_name'], $data['email_status'], $data['source'], $data['notes']];
        if ($id > 0) {
            $db->execute_query('UPDATE marketing_contacts SET email=?, first_name=?, last_name=?, email_status=?, source=?, notes=?, version=version+1 WHERE id=?', [...$values, $id]);
        } else {
            $db->execute_query('INSERT INTO marketing_contacts (email, first_name, last_name, email_status, source, notes) VALUES (?, ?, ?, ?, ?, ?)', $values);
            $id = (int) $db->insert_id;
        }
        $db->execute_query('DELETE FROM marketing_contact_tags WHERE contact_id=?', [$id]);
        $assignedTags = [];
        foreach ($data['tags'] as $tag) {
            $db->execute_query('INSERT INTO marketing_tags (name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$tag]);
            $tagId = (int) $db->insert_id;
            if (isset($assignedTags[$tagId])) continue;
            $assignedTags[$tagId] = true;
            $db->execute_query('INSERT INTO marketing_contact_tags (contact_id, tag_id) VALUES (?, ?)', [$id, $tagId]);
        }
        audienceEvent($db, $id, $actor, $previous ? 'contact_updated' : 'contact_added', [
            'previous_email' => $previous['email'] ?? null,
            'email' => $data['email'], 'previous_status' => $previous['email_status'] ?? null,
            'status' => $data['email_status'], 'consent_note' => $data['consent_note'],
        ]);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        $db->rollback();
        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) {
            throw new InvalidArgumentException('This email address already belongs to a connection, including archived connections. Open that connection to update it.');
        }
        throw $error;
    }
}

function audienceArchiveContact(mysqli $db, int $id, int $version, bool $archive, int $actor): void
{
    $db->begin_transaction();
    try {
        $db->execute_query('UPDATE marketing_contacts SET is_archived=?, version=version+1 WHERE id=? AND version=?', [(int) $archive, $id, $version]);
        if ($db->affected_rows !== 1) throw new InvalidArgumentException('This connection changed or is unavailable. Reload and try again.');
        audienceEvent($db, $id, $actor, $archive ? 'contact_archived' : 'contact_restored', []);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function audienceCsvCell(string $value): string
{
    // Prevent spreadsheet formula execution, including whitespace-prefixed input.
    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'" . $value : $value;
}

/** Preserve source dates without inventing a timezone or treating an import as consent. */
function audienceMarketingDetails(array $person, ?array $permissionEvent, ?array $importEvent): array
{
    $status=$person['email_status'];
    $label=match($status){'subscribed'=>'Opt in','unsubscribed'=>'Opt out','cleaned'=>'Cleaned','pending'=>'Requested',default=>'Added'};
    $source=(string)$person['source'];$raw='';$isImport=$importEvent&&(!$permissionEvent||(int)$importEvent['id']>(int)$permissionEvent['id']);
    if($isImport){
        $detail=audienceJson($importEvent['details_json']);$metadata=$detail['source_metadata']??[];
        $source=(string)($metadata['SOURCE']??$source);
        $keys=match($status){'subscribed'=>['OPTIN_TIME','CONFIRM_TIME'],'unsubscribed'=>['UNSUB_TIME'],'cleaned'=>['CLEAN_TIME'],default=>[]};
        foreach($keys as$key){$candidate=(string)($metadata[$key]??'');if($candidate!==''&&!str_starts_with($candidate,'0000-')){$raw=$candidate;break;}}
    }elseif($permissionEvent){
        $detail=audienceJson($permissionEvent['details_json']);
        $eventStatus=$detail['to']??$detail['status']??(($detail['action']??'')==='unsubscribe'?'unsubscribed':'');
        if($eventStatus===$status)$raw=$permissionEvent['created_at'];
    }
    $date='';$datetime='';$note=$isImport?'Original Mailchimp timestamp; the export does not specify its time zone.':'Recorded in UTC';
    if($raw!==''&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',$raw)){
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',substr($raw,0,19),new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
        if($parsed&&(!$errors||(!$errors['warning_count']&&!$errors['error_count']))){$date=$parsed->format('M j, Y, g:i A').($isImport?'':' UTC');$datetime=$parsed->format('Y-m-d\TH:i:s').($isImport?'':'Z');}
    }
    return ['label'=>$label,'date'=>$date,'datetime'=>$datetime,'source'=>$source,'note'=>$note];
}

/** Native history is stored in UTC; do not guess a zone for imported permission dates. */
function audienceProfileDate(string $value): string
{
    try { return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->format('M j, Y, g:i A').' UTC'; }
    catch (Exception) { return $value; }
}

/** Selection values use the same language codes accepted by connection settings. */
function audienceLanguageOptions(string $current=''):array
{
    $languages=[''=>'Not set','ar'=>'Arabic','bg'=>'Bulgarian','ca'=>'Catalan','zh'=>'Chinese','hr'=>'Croatian','cs'=>'Czech','da'=>'Danish','nl'=>'Dutch','en'=>'English','en-US'=>'English (United States)','en-GB'=>'English (United Kingdom)','et'=>'Estonian','fi'=>'Finnish','fr'=>'French','de'=>'German','el'=>'Greek','he'=>'Hebrew','hi'=>'Hindi','hu'=>'Hungarian','id'=>'Indonesian','it'=>'Italian','ja'=>'Japanese','ko'=>'Korean','lv'=>'Latvian','lt'=>'Lithuanian','ms'=>'Malay','no'=>'Norwegian','fa'=>'Persian','pl'=>'Polish','pt'=>'Portuguese','pt-BR'=>'Portuguese (Brazil)','ro'=>'Romanian','ru'=>'Russian','sr'=>'Serbian','sk'=>'Slovak','sl'=>'Slovenian','es'=>'Spanish','sw'=>'Swahili','sv'=>'Swedish','th'=>'Thai','tr'=>'Turkish','uk'=>'Ukrainian','ur'=>'Urdu','vi'=>'Vietnamese'];
    if($current!==''&&!isset($languages[$current]))$languages[$current]=$current;
    return $languages;
}

/** Pipe-delimited list cells use CSV quoting so names may contain pipes and quotes. */
function audienceListCell(array $values): string
{
    if (!$values) return '';
    $stream=fopen('php://temp','w+');
    fputcsv($stream,array_values($values),'|','"','');rewind($stream);
    $result=stream_get_contents($stream);fclose($stream);
    return substr($result,0,-1);
}
function audienceParseListCell(string $value): array
{
    if (trim($value)==='') return [];
    return array_values(array_filter(array_map(static fn($v)=>trim((string)$v),str_getcsv($value,'|','"','')),static fn($v)=>$v!==''));
}

/** Date columns use typed values, with empty dates last in either direction. */
function audienceConnectionOrder(string $sort,string $direction,array $fields):string
{
    $direction=$direction==='asc'?'ASC':'DESC';
    foreach($fields as$field)if($field['field_type']==='date'&&$sort==='custom:'.(int)$field['id']){
        $value="JSON_UNQUOTE(JSON_EXTRACT(c.custom_json,'$.\"".(int)$field['id']."\"'))";
        $date="CASE WHEN $value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN CAST($value AS DATE) ELSE NULL END";
        return "($date IS NULL) ASC,$date $direction,c.id $direction";
    }
    if(!in_array($sort,['email','first_name','last_name','email_status','source','created_at','updated_at'],true))$sort='created_at';
    return "c.$sort $direction,c.id $direction";
}
