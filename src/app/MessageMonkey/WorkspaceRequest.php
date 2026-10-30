<?php

declare(strict_types=1);

namespace Dnr\MessageMonkey;

use Dnr\Http\RequestInput;

/** Validated routing and access requirements for the MessageMonkey entry point. */
final class WorkspaceRequest
{
    private const CONTROLLERS = [
        'manual' => ['manual'],
        'assistant' => ['assistant'],
        'analytics' => ['home', 'analytics'],
        'inbox' => ['inbox'],
        'flows' => ['automations', 'flow', 'flow-templates', 'flow-report'],
        'collection' => ['forms', 'form', 'form-settings', 'landing-pages', 'surveys', 'collection-report', 'preferences', 'collection-create'],
        'design' => ['templates', 'designer'],
        'campaigns' => ['campaigns', 'campaign', 'campaign-manager', 'campaign-plan', 'campaign-review', 'campaign-send', 'campaign-delivery', 'content', 'brand', 'delivery'],
        'contacts' => ['contacts', 'contact', 'tags', 'groups', 'fields', 'settings', 'segments', 'import', 'jobs', 'job'],
    ];

    private const ACTION_PREFIXES = [
        'inbox' => ['inbox_'],
        'flows' => ['flow_'],
        'campaigns' => ['campaign_', 'delivery_'],
    ];

    private const TRANSFER_ACTIONS = [
        'import_upload', 'configure_import', 'start_import', 'undo_import', 'queue_export',
        'process_job', 'resume_job', 'cancel_job', 'template_package_import',
    ];

    /** @param array<string, mixed> $query */
    private function __construct(
        public readonly string $view,
        public readonly int $id,
        private readonly string $action,
        private readonly array $query,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public static function fromInput(array $query, array $post): self
    {
        return new self(
            RequestInput::enum($query, 'view', array_merge(...array_values(self::CONTROLLERS)), 'contacts'),
            RequestInput::positiveInt($post + $query, 'id') ?? 0,
            is_string($post['action'] ?? null) ? $post['action'] : '',
            $query,
        );
    }

    public function controller(): string
    {
        // Ordering preserves the entry point's established routing precedence:
        // e.g. a manual request stays read-only even with an inbox action posted.
        foreach (self::CONTROLLERS as $controller => $views) {
            if (in_array($this->view, $views, true)) return $controller;
            foreach (self::ACTION_PREFIXES[$controller] ?? [] as $prefix) {
                if (str_starts_with($this->action, $prefix)) return $controller;
            }
        }
        return 'contacts';
    }

    public function requiresDeliveryAdmin(): bool
    {
        return $this->view === 'delivery' || str_starts_with($this->action, 'delivery_');
    }

    public function requiresTransferAccess(): bool
    {
        return isset($this->query['download']) || isset($this->query['export']) || isset($this->query['template_export'])
            || ($this->view === 'collection-report' && isset($this->query['chart']))
            || ($this->query['api'] ?? '') === 'job'
            || in_array($this->view, ['import', 'jobs', 'job'], true)
            || in_array($this->action, self::TRANSFER_ACTIONS, true);
    }
}
