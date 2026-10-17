<?php
declare(strict_types=1);

const AUDIENCE_FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'birthday' => 'Birthday', 'address' => 'Address', 'phone' => 'Phone', 'url' => 'Website', 'dropdown' => 'Dropdown', 'radio' => 'Radio buttons', 'checkboxes' => 'Checkboxes'];

function audienceJson(string $json): array
{
    try { $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new InvalidArgumentException('The saved data is invalid. Reload and try again.'); }
    if (!is_array($data)) throw new InvalidArgumentException('Expected structured audience data.');
    return $data;
}

function audienceIds(mixed $values): array
{
    if (!is_array($values) || count($values) > 50000) throw new InvalidArgumentException('Invalid selection.');
    $ids = [];
    foreach ($values as $value) {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string) $value) || (int) $value < 1) throw new InvalidArgumentException('Invalid selection.');
        $ids[(int) $value] = (int) $value;
    }
    return array_values($ids);
}

function audienceFieldValue(array $field, mixed $value): string|array
{
    $options = is_array($field['options_json']) ? $field['options_json'] : audienceJson($field['options_json']);
    $type = $field['field_type'];
    if ($type === 'address' && is_array($value)) {
        $parts=[];
        foreach(['address1','address2','city','region','postal_code','country']as$key){
            $part=audienceText($value,$key,300);
            if(str_contains($part,"\n")||str_contains($part,"\r"))throw new InvalidArgumentException($field['label'].': use a single line for each address part.');
            $parts[]=$part;
        }
        if(implode('',$parts)!==''&&$parts[0]==='')throw new InvalidArgumentException($field['label'].': enter a street address.');
        // Keep the existing scalar storage/export format; blank middle lines preserve components.
        $value=rtrim(implode("\n",$parts),"\n");
    }
    if ($type === 'checkboxes') {
        if (!is_array($value)) $value = audienceParseListCell((string)$value);
        if (count($value) > 100) throw new InvalidArgumentException($field['label'] . ': too many choices.');
        foreach ($value as $choice) if (!is_string($choice) || !in_array($choice, $options, true)) throw new InvalidArgumentException($field['label'] . ': choose a listed option.');
        $result = array_values(array_unique($value));
    } else {
        $result = audienceText(['value' => $value], 'value', 2000);
        if ($result !== '') {
            $valid = match ($type) {
                'number' => preg_match('/^-?\d{1,15}(?:\.\d{1,8})?$/D', $result) === 1,
                'date' => audienceValidDate($result),
                'birthday' => preg_match('/^(\d{2})-(\d{2})$/D', $result, $parts) && checkdate((int) $parts[1], (int) $parts[2], 2000),
                'url' => filter_var($result, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($result, PHP_URL_SCHEME)), ['https', 'http'], true),
                'dropdown', 'radio' => in_array($result, $options, true),
                default => true,
            };
            if (!$valid) throw new InvalidArgumentException($field['label'] . ': enter a valid ' . $type . ' value.');
        }
    }
    if ($field['required'] && ($result === '' || $result === [])) throw new InvalidArgumentException($field['label'] . ' is required.');
    return $result;
}

function audienceValidDate(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $p)) return false;
    return checkdate((int) $p[2], (int) $p[3], (int) $p[1]);
}

function audienceRuleCatalog(array $fields, array $tags, array $groups): array
{
    $catalog = [];
    foreach (['email' => 'Email address', 'first_name' => 'First name', 'last_name' => 'Last name', 'source' => 'Signup source', 'language' => 'Language', 'timezone' => 'Time zone'] as $key => $label) $catalog[$key] = ['label' => $label, 'kind' => 'text', 'section' => 'Connection information'];
    $catalog['full_name']=['label'=>'Full name','kind'=>'text','section'=>'Connection information'];
    $catalog['source']['label']='Signup source detail';
    $catalog['signup_source']=['label'=>'Signup source','kind'=>'choice','options'=>audienceSignupSourceLabels(),'section'=>'How your connections were acquired'];
    $catalog['email_status'] = ['label' => 'Email subscription status', 'kind' => 'choice', 'options' => array_combine(AUDIENCE_STATUSES, AUDIENCE_STATUSES), 'section' => 'Email'];
    $catalog['is_archived'] = ['label' => 'Archived', 'kind' => 'choice', 'options' => ['1' => 'Archived', '0' => 'Active'], 'section' => 'Connection information'];
    foreach (['created_at' => 'Date added', 'updated_at' => 'Information changed'] as $key => $label) $catalog[$key] = ['label' => $label, 'kind' => 'date', 'section' => 'How your connections were acquired'];
    $catalog['tags'] = ['label' => 'Tags', 'kind' => 'membership', 'options' => array_column($tags, 'name', 'id'), 'section' => 'Tags and groups'];
    $opts = [];
    foreach ($groups as $group) foreach ($group['options'] as $option) $opts[$option['id']] = $group['name'] . ': ' . $option['name'];
    $catalog['groups'] = ['label' => 'Group membership', 'kind' => 'membership', 'options' => $opts, 'section' => 'Tags and groups'];
    foreach ($fields as $field) $catalog['custom:' . $field['id']] = ['label' => $field['label'], 'kind' => match ($field['field_type']) {'number' => 'number', 'date' => 'date', 'checkboxes' => 'set', default => 'text'}, 'section' => 'Audience fields'];
    $events = ['contact_added' => 'Connection added', 'contact_updated' => 'Information updated', 'subscription_changed' => 'Subscription changed', 'tags_changed' => 'Tags changed', 'groups_changed' => 'Groups changed', 'contact_archived' => 'Connection archived', 'contact_restored' => 'Connection restored', 'note_added' => 'Note added', 'imported' => 'Imported'];
    foreach ($events as $key => $label) $catalog['event:' . $key] = ['label' => $label, 'kind' => 'event', 'section' => 'Connection activity'];
    return $catalog;
}

function audienceRuleOperators(string $kind): array
{
    return match ($kind) {
        'membership' => ['any' => 'is any of', 'all' => 'is all of', 'none' => 'is none of', 'empty' => 'is blank', 'not_empty' => 'is not blank'],
        'event' => ['ever' => 'has happened', 'never' => 'has not happened', 'last_days' => 'happened in the last (days)', 'before' => 'happened before', 'after' => 'happened after'],
        'choice' => ['is' => 'is', 'is_not' => 'is not'],
        'number' => ['is' => 'is', 'is_not' => 'is not', 'gt' => 'is greater than', 'lt' => 'is less than', 'gte' => 'is at least', 'lte' => 'is at most', 'empty' => 'is blank', 'not_empty' => 'is not blank'],
        'date' => ['is' => 'is on', 'before' => 'is before', 'after' => 'is after', 'last_days' => 'is within the last (days)', 'empty' => 'is blank', 'not_empty' => 'is not blank'],
        'set' => ['contains' => 'contains choice', 'not_contains' => 'does not contain choice', 'empty' => 'is blank', 'not_empty' => 'is not blank'],
        default => ['is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain', 'starts' => 'starts with', 'ends' => 'ends with', 'empty' => 'is blank', 'not_empty' => 'is not blank'],
    };
}

/** Compile a bounded typed rule tree; only catalog-controlled expressions enter SQL. */
function audienceCompileRules(array $tree, array $catalog): array
{
    $params = []; $nodes = 0;
    $compile = function (array $node, int $depth) use (&$compile, &$params, &$nodes, $catalog): string {
        if (++$nodes > 100 || $depth > 5) throw new InvalidArgumentException('Use up to 100 conditions and five nested groups.');
        if (isset($node['rules'])) {
            if (!in_array($node['match'] ?? '', ['all', 'any'], true) || !is_array($node['rules'])) throw new InvalidArgumentException('Choose AND or OR for each group.');
            if (!$node['rules']) { if ($depth > 0) throw new InvalidArgumentException('Remove empty filter groups.'); return '1=1'; }
            $parts = [];
            foreach ($node['rules'] as $rule) { if (!is_array($rule)) throw new InvalidArgumentException('Invalid filter condition.'); $parts[] = $compile($rule, $depth + 1); }
            return '(' . implode($node['match'] === 'all' ? ' AND ' : ' OR ', $parts) . ')';
        }
        $key = $node['field'] ?? ''; $op = $node['operator'] ?? '';
        if (!is_string($key) || !isset($catalog[$key]) || !is_string($op)) throw new InvalidArgumentException('A segment field is unavailable. Edit its conditions.');
        $field = $catalog[$key]; $kind = $field['kind'];
        if (!isset(audienceRuleOperators($kind)[$op])) throw new InvalidArgumentException('Choose a valid condition for ' . $field['label'] . '.');
        if ($kind === 'membership') {
            $join = $key === 'tags' ? 'marketing_contact_tags m' : 'marketing_contact_groups m';
            $column = $key === 'tags' ? 'tag_id' : 'option_id';
            $base = "SELECT 1 FROM $join WHERE m.contact_id=c.id";
            if (in_array($op, ['empty', 'not_empty'], true)) return ($op === 'empty' ? 'NOT ' : '') . "EXISTS ($base)";
            $ids = audienceIds($node['value'] ?? []);
            if (!$ids || array_diff($ids, array_map('intval', array_keys($field['options'])))) throw new InvalidArgumentException('Choose available tags or groups for this audience.');
            array_push($params, ...$ids);
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($op === 'all') return '(SELECT COUNT(*) FROM ' . $join . " WHERE m.contact_id=c.id AND m.$column IN ($in)) = " . count($ids);
            return ($op === 'none' ? 'NOT ' : '') . "EXISTS ($base AND m.$column IN ($in))";
        }
        $value = audienceText(['value' => $node['value'] ?? ''], 'value', 2000);
        if ($kind === 'event') {
            $params[] = substr($key, 6);
            $sql = 'SELECT 1 FROM marketing_contact_events ev WHERE ev.contact_id=c.id AND ev.event_type=?';
            if ($op === 'last_days') { if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 36500) throw new InvalidArgumentException('Enter a day count between 1 and 36500.'); $sql .= ' AND ev.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'; $params[] = (int) $value; }
            if ($op === 'before' || $op === 'after') { if (!audienceValidDate($value)) throw new InvalidArgumentException('Enter a valid date.'); $sql .= ' AND DATE(ev.created_at) ' . ($op === 'before' ? '<' : '>') . ' ?'; $params[] = $value; }
            return ($op === 'never' ? 'NOT ' : '') . "EXISTS ($sql)";
        }
        $expr = match($key){'full_name'=>"CONCAT(c.first_name,' ',c.last_name)",'signup_source'=>audienceSignupSourceExpression(),default=>str_starts_with($key, 'custom:') ? "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.custom_json, '$.\"" . (int) substr($key, 7) . "\"')), '')" : 'c.' . $key};
        if ($op === 'empty' || $op === 'not_empty') return ($op === 'not_empty' ? 'NOT ' : '') . "(COALESCE($expr,'') IN ('','[]','null'))";
        if ($kind === 'set') { $params[] = json_encode($value, JSON_THROW_ON_ERROR); return ($op === 'not_contains' ? 'NOT ' : '') . "JSON_CONTAINS(COALESCE(NULLIF($expr,''),'[]'), ?)"; }
        if ($kind === 'choice' && !array_key_exists($value, $field['options'])) throw new InvalidArgumentException('Choose a listed value.');
        if ($kind === 'number') { if (!preg_match('/^-?\d{1,15}(?:\.\d{1,8})?$/D', $value)) throw new InvalidArgumentException('Enter a valid number.'); $expr = "CAST(NULLIF($expr,'') AS DECIMAL(24,8))"; }
        if ($kind === 'date') {
            if ($op === 'last_days') { if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 36500) throw new InvalidArgumentException('Enter a day count between 1 and 36500.'); $params[] = (int) $value; return "($expr >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) AND $expr <= UTC_TIMESTAMP())"; }
            if (!audienceValidDate($value)) throw new InvalidArgumentException('Enter a valid date.');
            $expr = "DATE(NULLIF($expr,''))";
        }
        $params[] = $value;
        if ($op === 'starts' || $op === 'ends') {
            $params[] = $value;
            return ($op === 'starts' ? 'LEFT' : 'RIGHT') . "($expr, CHAR_LENGTH(?)) = ?";
        }
        return match ($op) {
            'is' => "$expr = ?", 'is_not' => "$expr <> ?", 'gt', 'after' => "$expr > ?", 'lt', 'before' => "$expr < ?", 'gte' => "$expr >= ?", 'lte' => "$expr <= ?",
            'contains' => "LOCATE(?, $expr) > 0", 'not_contains' => "LOCATE(?, $expr) = 0",
            default => throw new InvalidArgumentException('Invalid condition.'),
        };
    };
    return [$compile($tree, 0), $params];
}

function audienceLegacyRules(array $filters, array $tags): array
{
    if (isset($filters['rules'])) return $filters;
    $rules = [];
    foreach (['status' => 'email_status', 'source' => 'source'] as $key => $field) if (!empty($filters[$key])) $rules[] = ['field' => $field, 'operator' => 'is', 'value' => $filters[$key]];
    if (!empty($filters['q'])) $rules[] = ['match' => 'any', 'rules' => array_map(fn($f) => ['field' => $f, 'operator' => 'contains', 'value' => $filters['q']], ['email','first_name','last_name'])];
    if (!empty($filters['tag'])) {
        $ids = array_column(array_filter($tags, fn($t) => $t['name'] === $filters['tag']), 'id');
        $rules[] = ['field' => 'tags', 'operator' => 'any', 'value' => $ids ?: [0]];
    }
    return ['match' => 'all', 'rules' => $rules];
}

function audienceListFilters(array $input): array
{
    $result=['q'=>audienceText($input,'q',254),'archived'=>audienceText($input,'archived')?:'0','tags'=>audienceIds($input['tags']??[])];
    if(!in_array($result['archived'],['0','1','all'],true))throw new InvalidArgumentException('Choose active or archived connections.');
    foreach(['status','source']as$key){$raw=$input[$key]??[];if(is_string($raw))$raw=$raw===''?[]:[$raw];if(!is_array($raw)||count($raw)>100)throw new InvalidArgumentException('Invalid filter.');$result[$key]=[];foreach($raw as$v){$v=audienceText(['value'=>$v],'value');if($key==='status'&&!in_array($v,AUDIENCE_STATUSES,true))throw new InvalidArgumentException('Invalid subscription status.');$result[$key][]=$v;}}
    return $result;
}

function audienceSignupSourceLabels(): array
{
    return ['api'=>'API','manual'=>'Manual entry','embed'=>'Embedded form','hosted'=>'Hosted signup form','import'=>'Import','landing'=>'Landing page','popup'=>'Popup form','survey'=>'Survey','mobile'=>'Mobile app','other'=>'Other','unknown'=>'Unknown'];
}

/** Classify actual stored provenance without replacing its original detail. */
function audienceSignupSourceExpression(): string
{
    return "(CASE WHEN LOWER(TRIM(COALESCE(c.source,''))) IN ('','mailchimp export') THEN 'unknown' WHEN LOWER(c.source) REGEXP '^api|webhook' THEN 'api' WHEN LOWER(c.source) REGEXP 'import|csv|file upload' THEN 'import' WHEN LOWER(c.source) REGEXP 'admin add|manual' THEN 'manual' WHEN LOWER(c.source) LIKE '%embed%' THEN 'embed' WHEN LOWER(c.source) LIKE '%landing%' THEN 'landing' WHEN LOWER(c.source) REGEXP 'pop.?up' THEN 'popup' WHEN LOWER(c.source) LIKE '%survey%' THEN 'survey' WHEN LOWER(c.source) REGEXP 'ios|android|mobile' THEN 'mobile' WHEN LOWER(c.source) REGEXP 'hosted|signup form|sign-up form' THEN 'hosted' ELSE 'other' END)";
}

/** Quick filters and the advanced editor must describe the same population. */
function audienceActiveRules(array $filters,array $advanced=['match'=>'all','rules'=>[]],bool $search=true): array
{
    $parts=[];
    foreach(['status'=>'email_status','source'=>'source']as$key=>$field){
        $choices=[];foreach(array_values(array_unique($filters[$key]??[]))as$value){
            $bucket=$key==='source'&&str_starts_with($value,'type:');$choices[]=['field'=>$bucket?'signup_source':$field,'operator'=>'is','value'=>$bucket?substr($value,5):$value];
        }
        if($choices)$parts[]=count($choices)===1?$choices[0]:['match'=>'any','rules'=>$choices];
    }
    if(!empty($filters['tags']))$parts[]=['field'=>'tags','operator'=>'any','value'=>$filters['tags']];
    if($search&&($filters['q']??'')!=='')$parts[]=['match'=>'any','rules'=>[['field'=>'email','operator'=>'contains','value'=>$filters['q']],['field'=>'full_name','operator'=>'contains','value'=>$filters['q']]]];
    if(!$parts)return $advanced;
    if(!empty($advanced['rules'])){if(($advanced['match']??'')==='all')array_push($parts,...$advanced['rules']);else $parts[]=$advanced;}
    return ['match'=>'all','rules'=>$parts];
}
