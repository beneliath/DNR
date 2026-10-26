<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("MessageMonkey performance checks skipped (disposable database required).\n");
}
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src').'/bootstrap.php';
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src').'/audience_store.php';
$db = applicationDatabaseConnection();
$checks = 0;
function mmPerformanceExpect(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function mmMeasure(mysqli $db, callable $read): array
{
    $before = (int)$db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
    $start = hrtime(true);
    $result = $read();
    $milliseconds = (hrtime(true) - $start) / 1e6;
    $queries = (int)$db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'] - $before;
    return compact('result', 'queries', 'milliseconds');
}
// Use direct inserts inside a single rollback-only transaction. No workers, sends,
// persistent account data, or timing-based pass/fail thresholds are involved.
$db->begin_transaction();
try {
    $db->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Performance fixture',JSON_OBJECT())");
    $aid = (int)$db->insert_id;
    $store = new AudienceStore($db, $aid, 1, 'Performance fixture');
    $db->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Foreign performance fixture',JSON_OBJECT())");
    $other = (int)$db->insert_id;
    $foreign = new AudienceStore($db, $other, 1, 'Foreign fixture');
    $ids = []; $options = []; $tags = [];
    foreach ([$aid, $other] as $scope) {
        $db->execute_query("INSERT INTO marketing_tags(audience_id,name) VALUES (?,'Zulu'),(?,'Alpha')", [$scope, $scope]);
        $tags[$scope] = [(int)$db->insert_id, (int)$db->insert_id + 1];
        for ($i = 0; $i < 20; $i++) {
            $db->execute_query('INSERT INTO marketing_group_categories(audience_id,name) VALUES (?,?)', [$scope, sprintf('Group %02d', $i)]);
            $group = (int)$db->insert_id;
            $db->execute_query("INSERT INTO marketing_group_options(category_id,name,position) VALUES (?,'Second',2),(?,'First',1)", [$group, $group]);
            $options[$scope][] = (int)$db->insert_id;
        }
        for ($i = 0; $i < 100; $i++) {
            $db->execute_query("INSERT INTO marketing_contacts(audience_id,email,notes,custom_json,is_archived,deleted_at) VALUES (?,?, '',JSON_OBJECT(),?,?)", [$scope, "performance-$scope-$i@example.test", (int)($i === 98), $i === 99 ? '2026-01-01 00:00:00' : null]);
            $id = (int)$db->insert_id; $ids[$scope][] = $id;
            if ($i === 0) continue; // No memberships must still return empty arrays.
            foreach ($tags[$scope] as $tag) $db->execute_query('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)', [$id, $tag]);
            foreach (array_slice($options[$scope], 0, 2) as $option) $db->execute_query('INSERT INTO marketing_contact_groups(contact_id,option_id) VALUES (?,?)', [$id, $option]);
        }
    }
    $legacyRelations = static function(array $people) use ($store, $aid): array {
        $tags = []; $groups = [];
        foreach ($people as $id) {
            $tags[$id] = $store->all('SELECT t.id,t.name FROM marketing_tags t JOIN marketing_contact_tags ct ON ct.tag_id=t.id WHERE ct.contact_id=? AND t.audience_id=? ORDER BY t.name', [$id,$aid]);
            $groups[$id] = $store->all('SELECT o.name,g.id category_id,g.name category FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id JOIN marketing_contact_groups cg ON cg.option_id=o.id WHERE cg.contact_id=? AND g.audience_id=? ORDER BY g.name,o.position', [$id,$aid]);
        }
        return compact('tags','groups');
    };
    $measurements = [];
    foreach ([10, 100] as $size) {
        $people = array_slice($ids[$aid], 0, $size);
        $legacy = mmMeasure($db, fn() => $legacyRelations($people));
        $batch = mmMeasure($db, fn() => $store->contactListRelations($people));
        mmPerformanceExpect($legacy['result'] === $batch['result'], 'Contact tags/groups and ordering must be unchanged');
        $measurements['contacts_'.$size] = ['before_queries'=>$legacy['queries'], 'after_queries'=>$batch['queries'], 'before_ms'=>$legacy['milliseconds'], 'after_ms'=>$batch['milliseconds']];
    }
    $groups = mmMeasure($db, fn() => $store->groups());
    mmPerformanceExpect(count($groups['result']) === 20, 'Group categories remain scoped');
    foreach ($groups['result'] as $i => $group) {
        mmPerformanceExpect(array_column($group['options'], 'name') === ['First','Second'], 'Group options retain position order');
        mmPerformanceExpect((int)$group['options'][1]['contact_count'] === ($i < 2 ? 97 : 0), 'Counts exclude archived, deleted and foreign connections');
    }
    $measurements['groups_20'] = ['queries'=>$groups['queries'], 'ms'=>$groups['milliseconds']];
    $threads = []; $messageIds = [];
    foreach ([$aid, $other] as $scope) {
        $db->execute_query("INSERT INTO marketing_inbox_threads(audience_id,source,subject,sender_email,sender_name) VALUES (?,'direct','Performance conversation','fixture@example.test','Fixture')", [$scope]);
        $thread = (int)$db->insert_id; $threads[$scope] = $thread;
        for ($i = 0; $i < 100; $i++) {
            $db->execute_query("INSERT INTO marketing_service_mail(audience_id,kind,operation_key,payload_ciphertext,message_id,state,next_attempt_at,expires_at) VALUES (?,'test',?,'fixture',?,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$scope, hash('sha256', "$scope-mail-$i"), "<fixture-$scope-$i@example.test>"]);
            $mail = (int)$db->insert_id;
            $db->execute_query("INSERT INTO marketing_inbox_messages(audience_id,thread_id,direction,source_key,references_json,body_text,body_html,service_mail_id) VALUES (?,?,'outbound',?,JSON_ARRAY(),?,'',?)", [$scope, $thread, hash('sha256', "$scope-message-$i"), "Message $i", $i === 0 ? null : $mail]);
            $message = (int)$db->insert_id; $messageIds[$scope][] = $message;
            if ($i === 0) continue;
            $db->execute_query("INSERT INTO marketing_inbox_delivery_events(audience_id,service_mail_id,event_type,event_at) VALUES (?,?,'send','2026-10-01 00:00:00'),(?,?,'delivered','2026-10-01 00:01:00')", [$scope,$mail,$scope,$mail]);
            $db->execute_query("INSERT INTO marketing_inbox_attachments(audience_id,message_id,filename,mime_type,byte_size,sha256,content) VALUES (?,?,?,'text/plain',7,?,'fixture')", [$scope,$message,"file-$i.txt",hash('sha256','fixture')]);
        }
    }
    foreach ([10, 100] as $size) {
        $messages = mmMeasure($db, fn() => $store->inboxMessages($threads[$aid], 0, 1, $size));
        mmPerformanceExpect(array_map('intval', array_column($messages['result'],'id')) === array_slice($messageIds[$aid], -$size), 'Messages are scoped, paged and chronological');
        foreach ($messages['result'] as $message) {
            $i = array_search((int)$message['id'], $messageIds[$aid], true);
            mmPerformanceExpect(array_column($message['delivery_events'],'event_type') === ($i === 0 ? [] : ['send','delivered']), 'Delivery events stay with their message and retain order');
            mmPerformanceExpect(array_column($message['attachments'],'filename') === ($i === 0 ? [] : ["file-$i.txt"]), 'Attachment metadata stays with its message');
            mmPerformanceExpect(!isset($message['attachments'][0]['content']), 'Attachments do not load blob content into the message list');
        }
        $measurements['messages_'.$size] = ['queries'=>$messages['queries'], 'ms'=>$messages['milliseconds']];
    }
    $page = $store->inboxMessages($threads[$aid], 0, 2, 10);
    mmPerformanceExpect(array_map('intval',array_column($page,'id')) === array_slice($messageIds[$aid], -20, 10), 'Second page has no overlap');
    $before = $store->inboxMessages($threads[$aid], $messageIds[$aid][50], 1, 10);
    mmPerformanceExpect(array_map('intval',array_column($before,'id')) === array_slice($messageIds[$aid], 40, 10), 'Before cursor remains exclusive');
    mmPerformanceExpect($store->inboxMessages($threads[$aid], $messageIds[$aid][0]) === [], 'Empty message page remains empty');
    try { $store->inboxMessages($threads[$other]); throw new RuntimeException('Foreign thread was exposed'); } catch (InvalidArgumentException) { $checks++; }
    echo json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    foreach ([10, 100] as $size) {
        mmPerformanceExpect($measurements['contacts_'.$size]['after_queries'] <= 2, 'Contact relations must use at most two SELECTs per page');
        mmPerformanceExpect($measurements['messages_'.$size]['queries'] <= 6, 'Conversation loading must have a constant query budget');
    }
    mmPerformanceExpect($groups['queries'] <= 2, 'Group catalog must use at most two SELECTs');
    $empty = mmMeasure($db, fn() => $store->contactListRelations([]));
    mmPerformanceExpect($empty['queries'] === 0 && $empty['result'] === ['tags'=>[], 'groups'=>[]], 'Empty contact page needs no queries');
    $cross = $store->contactListRelations([$ids[$aid][1],$ids[$other][1]]);
    mmPerformanceExpect($cross['tags'][$ids[$other][1]] === [] && $cross['groups'][$ids[$other][1]] === [], 'Foreign memberships are not returned');
    echo "MessageMonkey performance: $checks checks passed.\n";
} finally {
    $db->rollback();
}
