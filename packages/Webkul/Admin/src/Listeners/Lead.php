<?php

namespace Webkul\Admin\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Admin\Jobs\NotifyWarehouseManager;
use Webkul\Email\Repositories\EmailRepository;
use Webkul\Lead\Repositories\LeadRepository;

class Lead
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected EmailRepository $emailRepository,
        protected LeadRepository $leadRepository
    ) {}

    /**
     * @param  \Webkul\Lead\Models\Lead  $lead
     * @return void
     */
    public function linkToEmail($lead)
    {
        if (! request('email_id')) {
            return;
        }

        $this->emailRepository->update([
            'lead_id' => $lead->id,
        ], request('email_id'));
    }

    /**
     * Handle lead update event for notifications.
     *
     * @param  \Webkul\Lead\Models\Lead  $lead
     * @return void
     */
    public function handleLeadUpdate($lead)
    {
        try {
            // Check if feature is enabled in config
            if (! core()->getConfigData('leads.notifications.confirmed_order.enabled')) {
                return;
            }

            $stage = $lead->stage;
            if (! $stage) {
                return;
            }

            // Check if stage is "Confirmado" (by code or name)
            $stageName = strtolower($stage->name ?? '');
            $stageCode = strtolower($stage->code ?? '');

            if ($stageCode !== 'confirmado' && ! str_contains($stageName, 'confirmado')) {
                return;
            }

            // Identify Warehouse (Ciudad) attribute
            // We assume the attribute code is 'ciudad' as per user request context
            $warehouseId = $lead->getCustomAttributeValue($lead->getCustomAttributes()->where('code', 'ciudad')->first());

            if (! $warehouseId) {
                // Try 'warehouse_id' as fallback
                $warehouseId = $lead->getCustomAttributeValue($lead->getCustomAttributes()->where('code', 'warehouse_id')->first());
            }

            if (! $warehouseId) {
                Log::warning("Lead Notification: No warehouse/ciudad associated with Lead #{$lead->id}");

                return;
            }

            // Dispatch async job
            NotifyWarehouseManager::dispatch($lead, $warehouseId);

        } catch (\Exception $e) {
            Log::error('Lead Notification Listener Error: '.$e->getMessage());
        }
    }
}
