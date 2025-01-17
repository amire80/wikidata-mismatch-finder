<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkReviewsPutRequest;
use App\Http\Requests\MismatchGetRequest;
use App\Services\WikibaseAPIClient;
use Illuminate\Support\Facades\Auth;
use App\Models\Mismatch;
use App\Services\StatsdAPIClient;
use Illuminate\Support\Facades\App;
use Inertia\Response;
use Illuminate\Support\LazyCollection;

class ResultsController extends Controller
{
    use Traits\ReviewMismatch, Traits\StatsTracker;

    /**
     * Instantiate a new controller instance.
     *
     * @return void
     */
    public function __construct(StatsdAPIClient $statsd)
    {
        $this->middleware('simulateError');
        $this->statsd = $statsd;
    }

    public function index(MismatchGetRequest $request, WikibaseAPIClient $wikidata): Response
    {
        $user = Auth::user() ? [
            'name' => Auth::user()->username,
            'id' => Auth::user()->mw_userid
        ] : null;

        $requestedItemIds = $request->input('ids');

        $mismatches = Mismatch::with('importMeta.user')
            ->whereIn('item_id', $requestedItemIds)
            ->where('review_status', 'pending')
            ->whereHas('importMeta', function ($import) {
                $import->where('expires', '>=', now());
            })
            ->lazy();

        $propertyIds = $this->extractPropertyIds($mismatches);
        $datatypes = $wikidata->getPropertyDatatypes($propertyIds);

        $entityIds = array_unique(
            array_merge(
                $propertyIds,
                $this->extractItemIds($mismatches, $datatypes),
                $requestedItemIds
            )
        );

        $lang = App::getLocale();

        $timeValues = $this->extractTimeValues($mismatches, $datatypes);
        $parsedTimeValues = $wikidata->parseValues($timeValues);
        $updatedTimeValues = $this->updateTimeValues($mismatches, $parsedTimeValues);
        $formattedTimeValues = $wikidata->formatValues($updatedTimeValues, $lang);

        $props = array_merge(
            [
                'user' => $user,
                'item_ids' => $requestedItemIds,
                // Use wikidata to fetch labels for found entity ids
                'labels' => $wikidata->getLabels($entityIds, $lang),
                'formatted_values' => $formattedTimeValues,
            ],
            // only add 'results' prop if mismatches have been found
            $mismatches->isNotEmpty() ? [ 'results' => $mismatches->groupBy('item_id') ] : []
        );

        $this->trackRequestStats();

        return inertia('Results', $props);
    }

    private function updateTimeValues(LazyCollection $mismatches, array $parsedTimeValues)
    {
        $updatedTimeValues = [];

        foreach ($mismatches as $mismatch) {
            $pid = $mismatch->property_id;
            $wikidataValue = $mismatch->wikidata_value;
            if (isset($parsedTimeValues[$pid][$wikidataValue])) {
                $metaWikidataValue = $mismatch->meta_wikidata_value;
                $key = $metaWikidataValue . '|' . $wikidataValue;
                $updatedTimeValues[$pid][$key] = $parsedTimeValues[$pid][$wikidataValue];
                // check if a calendar model is specified and assign it if so
                if ($metaWikidataValue) {
                    $calendarModel = 'http://www.wikidata.org/entity/' . $metaWikidataValue;
                    $updatedTimeValues[$pid][$key]['value']['calendarmodel'] = $calendarModel;
                }
            }
        }

        return $updatedTimeValues;
    }

    private function extractPropertyIds(LazyCollection $mismatches): array
    {
        $idsAsKeys = [];

        foreach ($mismatches as $mismatch) {
            $idsAsKeys[$mismatch->property_id] = null;
        }

        return array_keys($idsAsKeys);
    }

    private function extractItemIds(LazyCollection $mismatches, array $datatypes): array
    {
        $idsAsKeys = [];

        foreach ($mismatches as $mismatch) {
            $idsAsKeys[$mismatch->item_id] = null;
            
            if ($mismatch->wikidata_value !== '') {
                if ($datatypes[$mismatch->property_id] === 'wikibase-item') {
                    $idsAsKeys[$mismatch->wikidata_value] = null;
                }
            }
        }

        return array_keys($idsAsKeys);
    }

    private function extractTimeValues(LazyCollection $mismatches, array $datatypes): array
    {
        $valuesByPropertyId = [];

        foreach ($mismatches as $mismatch) {
            $propertyId = $mismatch->property_id;
            if ($datatypes[$propertyId] === 'time') {
                $valuesByPropertyId[$propertyId][] = $mismatch->wikidata_value;
            }
        }

        return $valuesByPropertyId;
    }

    /**
     * Update review_statuses of a batch of mismatches
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update(BulkReviewsPutRequest $request)
    {
        foreach ($request->input() as $mismatch_id => $decision) {
            $mismatch = Mismatch::findorFail($mismatch_id);

            $old_status = $mismatch->review_status;
            $this->saveToDb($mismatch, $request->user(), $decision['review_status']);
            $this->logToFile($mismatch, $request->user(), $old_status);
            $this->trackReviewStats();
        }
    }
}
