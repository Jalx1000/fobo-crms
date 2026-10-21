<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Mail\ConfirmedLeadNotification;
use Webkul\Warehouse\Repositories\WarehouseRepository;

class NotifyWarehouseManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param  \Webkul\Lead\Models\Lead  $lead
     * @param  int  $warehouseId
     * @return void
     */
    public function __construct(
        protected $lead,
        protected $warehouseId
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $warehouseRepository = app(WarehouseRepository::class);
            $warehouse = $warehouseRepository->find($this->warehouseId);

            if (! $warehouse) {
                Log::error("Lead Notification: Warehouse not found (ID: {$this->warehouseId}) for Lead #{$this->lead->id}");

                return;
            }

            $emails = $warehouse->contact_emails;
            if (empty($emails)) {
                Log::warning("Lead Notification: No contact emails found for Warehouse '{$warehouse->name}' (Lead #{$this->lead->id})");

                return;
            }

            $details = [
                'customer_name'     => $this->lead->person->name,
                'confirmation_date' => now()->format('d/m/Y H:i'),
                'delivery_address'  => $this->lead->person->organization->address ?? 'N/A',
            ];

            Mail::to($emails)->send(new ConfirmedLeadNotification($this->lead, $details));

            Log::info("Lead Notification: Email sent to Warehouse '{$warehouse->name}' for Lead #{$this->lead->id}");

        } catch (\Exception $e) {
            Log::error('Lead Notification Error: '.$e->getMessage(), [
                'lead_id'      => $this->lead->id,
                'warehouse_id' => $this->warehouseId,
                'trace'        => $e->getTraceAsString(),
            ]);

            throw $e; // Throw exception to trigger retry
        }
    }
}
