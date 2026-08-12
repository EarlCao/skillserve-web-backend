<?php

namespace App\Modules\ServiceCategories\Listeners;

use App\Models\User;
use App\Modules\ServiceCategories\Events\ServiceCategoryCreated;
use App\Modules\ServiceCategories\Events\ServiceCategoryDeleted;
use App\Modules\ServiceCategories\Events\ServiceCategoryStatusChanged;
use App\Modules\ServiceCategories\Events\ServiceCategoryUpdated;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryCreated;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryDeleted;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryUpdated;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;

/**
 * Persists Service Category Management events into the Spatie activity log.
 *
 * Registered explicitly in AppServiceProvider (module listeners live outside
 * app/Listeners, so auto-discovery does not apply).
 */
class LogServiceCategoryActivity
{
    /**
     * Handle the service-category-module events.
     */
    public function handle(
        ServiceCategoryCreated|ServiceCategoryUpdated|ServiceCategoryDeleted|ServiceCategoryStatusChanged|
        ServiceSubcategoryCreated|ServiceSubcategoryUpdated|ServiceSubcategoryDeleted $event,
    ): void {
        match (true) {
            $event instanceof ServiceCategoryCreated => $this->log(
                'service_categories', $event->actor, $event->category,
                ['created' => $event->data], 'service_category_created',
            ),
            $event instanceof ServiceCategoryUpdated => $this->log(
                'service_categories', $event->actor, $event->category,
                ['before' => $event->before, 'after' => $event->after], 'service_category_updated',
            ),
            $event instanceof ServiceCategoryDeleted => $this->log(
                'service_categories', $event->actor, $event->category,
                ['name' => $event->category->name], 'service_category_deleted',
            ),
            $event instanceof ServiceCategoryStatusChanged => $this->log(
                'service_categories', $event->actor, $event->category,
                ['from' => $event->from, 'to' => $event->to], 'service_category_status_changed',
            ),
            $event instanceof ServiceSubcategoryCreated => $this->log(
                'service_subcategories', $event->actor, $event->subcategory,
                ['created' => $event->data, 'category_id' => $event->subcategory->category_id],
                'service_subcategory_created',
            ),
            $event instanceof ServiceSubcategoryUpdated => $this->log(
                'service_subcategories', $event->actor, $event->subcategory,
                ['before' => $event->before, 'after' => $event->after], 'service_subcategory_updated',
            ),
            default => $this->log(
                'service_subcategories', $event->actor, $event->subcategory,
                ['name' => $event->subcategory->name, 'category_id' => $event->subcategory->category_id],
                'service_subcategory_deleted',
            ),
        };
    }

    /**
     * Write a single activity-log entry.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(
        string $logName,
        User $actor,
        ServiceCategory|ServiceSubcategory $subject,
        array $properties,
        string $description,
    ): void {
        activity($logName)
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
