<?php
declare(strict_types=1);
require_once __DIR__ . '/audience_helpers.php';
require_once __DIR__ . '/audience_rules.php';
require_once __DIR__ . '/audience_jobs.php';
require_once __DIR__ . '/audience_settings.php';
require_once __DIR__ . '/audience_campaigns.php';
require_once __DIR__ . '/audience_campaign_advanced.php';
require_once __DIR__ . '/audience_design.php';
require_once __DIR__ . '/audience_design_store.php';
require_once __DIR__ . '/audience_content.php';
require_once __DIR__ . '/audience_delivery_setup.php';
require_once __DIR__ . '/audience_dispatch.php';
require_once __DIR__ . '/audience_delivery_runtime.php';
require_once __DIR__ . '/audience_ses.php';
require_once __DIR__ . '/audience_search.php';
require_once __DIR__ . '/audience_collection.php';
require_once __DIR__ . '/audience_flow_store.php';
require_once __DIR__ . '/audience_inbox_store.php';
require_once __DIR__ . '/audience_analytics.php';

require_once __DIR__ . '/message_monkey/store/contacts.php';
require_once __DIR__ . '/message_monkey/store/definitions.php';

final class AudienceStore
{
    use AudienceContacts;
    use AudienceDefinitions;
    use AudienceJobs;
    use AudienceSettings;
    use AudienceCampaigns;
    use AudienceCampaignAdvanced;
    use AudienceDesigns;
    use AudienceDesignExtras;
    use AudienceContent;
    use AudienceDeliverySetup;
    use AudienceDispatch;
    use AudienceDeliveryRuntime;
    use AudienceSesSetup;
    use AudienceCollection;
    use AudienceFlows;
    use AudienceInbox;
    use AudienceAnalytics;
    private int $transactionDepth = 0;
    public function __construct(public readonly mysqli $db, public readonly int $audienceId, public readonly int $actorId, public readonly string $actorLabel)
    {
        if (!$this->one('SELECT id FROM marketing_audiences WHERE id=?', [$audienceId])) throw new InvalidArgumentException('This audience is unavailable.');
    }
    public function all(string $sql, array $params = []): array { return $this->db->execute_query($sql, $params)->fetch_all(MYSQLI_ASSOC); }
    public function one(string $sql, array $params = []): ?array { return $this->db->execute_query($sql, $params)->fetch_assoc(); }
    public function write(string $sql, array $params = []): void { $this->db->execute_query($sql, $params); }
    public function transaction(callable $operation): mixed
    {
        $depth = $this->transactionDepth++;
        if ($depth === 0) $this->db->begin_transaction(); else $this->db->query('SAVEPOINT audience_' . $depth);
        try {
            $result = $operation();
            if ($depth === 0) $this->db->commit(); else $this->db->query('RELEASE SAVEPOINT audience_' . $depth);
            return $result;
        } catch (Throwable $error) {
            if ($depth === 0) $this->db->rollback(); else $this->db->query('ROLLBACK TO SAVEPOINT audience_' . $depth);
            if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) throw new InvalidArgumentException('That email address or name already exists in this audience. Open the existing record to update it.');
            throw $error;
        } finally { --$this->transactionDepth; }
    }
}
