<?php

namespace App\Http\Controllers\Royalties;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\RoyaltyPeriod;
use App\Services\Activity\ActivityLogger;
use App\Services\Royalties\StatementService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Artist royalty statement for one period: on screen (printable) and as CSV/XLSX.
 */
class StatementController extends Controller
{
    public function show(RoyaltyPeriod $period, Artist $artist, StatementService $statements): View
    {
        $this->authorize('view', $artist);

        return view('royalties.statement', $statements->build($artist, $period) + [
            'currency' => config('labeldesk.currency'),
        ]);
    }

    public function export(RoyaltyPeriod $period, Artist $artist, string $format, StatementService $statements, ActivityLogger $activity): StreamedResponse
    {
        $this->authorize('view', $artist);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        $activity->log('royalty.statement_exported', __('Exported the :period statement of :artist (:format)', [
            'period' => $period->name, 'artist' => $artist->name, 'format' => strtoupper($format),
        ]), $artist);

        return $statements->export($artist, $period, $format);
    }
}
