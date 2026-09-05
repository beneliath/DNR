<?php
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Cursor seek integration tests skipped (requires disposable database).\n"; exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
/** Exercise the route's actual predicate against an independently sorted baseline. */
function routeCursorPredicate(string $source, string $route, ?array $cursor, string $order_direction, string $sort_column): array {
    $text = file_get_contents($source . '/' . $route . '.php');
    $start = strpos($text, '$cursor_filter =');
    $end = strpos($text, '$query_limit =', $start);
    eval(substr($text, $start, $end - $start));
    return [$cursor_filter, $cursor_types, $cursor_values];
}
$prefix = 'Cursor' . bin2hex(random_bytes(5));
$ids = [];
try {
    $org = $conn->prepare('INSERT INTO organizations (organization_name,is_deleted,notes) VALUES (?,?,?)');
    $contact = $conn->prepare("INSERT INTO contacts (organization_id,contact_first_name,contact_last_name,contact_email,contact_role,is_deleted) VALUES (?,?,?,?,'other',?)");
    for ($n=0; $n<64; $n++) {
        $name = $prefix . ' ' . $n; $archived = $n%2; $notes = $n%3===0 ? 'special' : '';
        $org->bind_param('sis',$name,$archived,$notes); $org->execute(); $id=(int)$conn->insert_id; $ids[]=$id;
        for ($c=0;$c<3;$c++) {
            $first=['Alex','Alex','Zoe'][$c]; $last='Name'.(int)($n/8); $email=$prefix.$n.$c.'@example.test';
            $contact->bind_param('isssi',$id,$first,$last,$email,$archived); $contact->execute();
        }
    }
    $idList=implode(',',$ids);
    foreach (['ASC','DESC'] as $direction) foreach ([0,1] as $archive) foreach (['organizations','last_name','organization'] as $sort) {
        $isOrg=$sort==='organizations';
        $from=$isOrg ? "organizations o WHERE o.id IN ($idList) AND o.is_deleted=$archive"
            : "contacts c LEFT JOIN organizations o ON c.organization_id=o.id WHERE c.organization_id IN ($idList) AND c.is_deleted=$archive";
        $select=$isOrg ? 'o.id,o.organization_name' : 'c.id,c.contact_first_name,c.contact_last_name,o.organization_name';
        $order=$isOrg ? "o.organization_name $direction,o.id $direction" : ($sort==='organization' ? "COALESCE(o.organization_name,'') $direction," : '')."c.contact_last_name $direction,c.contact_first_name $direction,c.id $direction";
        $baseline=$conn->query("SELECT $select FROM $from ORDER BY $order")->fetch_all(MYSQLI_ASSOC);
        $seen=[];$cursor=null;
        do {
            [$filter,$types,$values]=routeCursorPredicate($source,$isOrg?'organizations':'contacts',$cursor,$direction,$sort);
            $stmt=$conn->prepare("SELECT $select FROM $from $filter ORDER BY $order LIMIT 7");
            if ($types!=='') $stmt->bind_param($types,...$values);
            $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach($rows as $row) $seen[]=$row;
            if ($rows===[]) break;
            $last=$rows[array_key_last($rows)];
            $cursor=$isOrg?['name'=>$last['organization_name'],'id'=>$last['id']]:['organization'=>$last['organization_name'],'last_name'=>$last['contact_last_name'],'first_name'=>$last['contact_first_name'],'id'=>$last['id']];
            if(count($seen)>count($baseline)) throw new RuntimeException('Cursor repeated rows');
        } while (true);
        if ($seen!=$baseline) throw new RuntimeException("Cursor omitted or reordered rows: $sort $direction $archive");
    }
    echo "Cursor seek integration tests passed (duplicate names, both directions, archive views and all sorts).\n";
} finally {
    if($ids!==[]) { $list=implode(',',$ids);$conn->query("DELETE FROM contacts WHERE organization_id IN ($list)");$conn->query("DELETE FROM organizations WHERE id IN ($list)"); }
}
