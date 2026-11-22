<?php

namespace App\Services\Royalties;

use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Models\RoyaltyEarning;
use App\Models\RoyaltyPeriod;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Artist royalty statements: earnings by track for a period plus the account
 * movement (opening balance, entries in the period, closing balance).
 */
class StatementService
{
    /**
     * @return array{
     *   artist: Artist, period: RoyaltyPeriod, provisional: bool,
     *   rows: Collection<int, array{title: string, release: string, isrc: ?string, units: int, revenue: string, share: string, amount: string}>,
     *   totals: array{units: int, revenue: string, amount: string},
     *   opening: string, entries: Collection<int, ArtistLedgerEntry>, closing: string
     * }
     */
    public function build(Artist $artist, RoyaltyPeriod $period): array
    {
        $earnings = RoyaltyEarning::query()
            ->with(['track:id,title,version,isrc,release_id', 'track.release:id,title,version,deleted_at', 'release:id,title,version,deleted_at'])
            ->where('royalty_period_id', $period->id)
            ->where('artist_id', $artist->id)
            ->get();

        $rows = $earnings->map(fn (RoyaltyEarning $e) => [
            'title' => $e->track?->displayTitle() ?? __('(release sales)'),
            'release' => ($e->track?->release ?? $e->release)?->displayTitle() ?? '—',
            'isrc' => $e->track?->formattedIsrc(),
            'units' => $e->units,
            'revenue' => (string) $e->revenue,
            'share' => (string) $e->artist_share,
            'amount' => (string) $e->artist_amount,
        ])->sortBy([['release', 'asc'], ['title', 'asc']])->values();

        $start = $period->starts_on->toDateString();
        $end = $period->ends_on->toDateString();
        $opening = ArtistAccountService::balance($artist, $start);
        $entries = ArtistLedgerEntry::query()->where('artist_id', $artist->id)
            ->whereDate('entry_date', '>=', $start)->whereDate('entry_date', '<=', $end)->orderBy('entry_date')->orderBy('id')->get();

        return [
            'artist' => $artist,
            'period' => $period,
            'provisional' => ! $period->isLocked(),
            'rows' => $rows,
            'totals' => [
                'units' => (int) $rows->sum('units'),
                'revenue' => Decimal::sum($rows->pluck('revenue')),
                'amount' => Decimal::sum($rows->pluck('amount')),
            ],
            'opening' => $opening,
            'entries' => $entries,
            'closing' => Decimal::add($opening, Decimal::sum($entries->map(fn ($e) => (string) $e->amount))),
        ];
    }

    public function export(Artist $artist, RoyaltyPeriod $period, string $format): StreamedResponse
    {
        $statement = $this->build($artist, $period);
        $currency = (string) config('labeldesk.currency');
        $base = preg_replace('/[^A-Za-z0-9\-]+/', '-', $artist->name.'-'.$period->name) ?: 'statement';
        $filename = 'statement-'.trim($base, '-').'.'.$format;

        return response()->streamDownload(function () use ($statement, $format, $currency) {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');

            $add = fn (array $cells) => $writer->addRow(Row::fromValues($cells));

            $add([__('Royalty statement'), $statement['artist']->name, $statement['period']->name.($statement['provisional'] ? ' ('.__('provisional').')' : '')]);
            $add([__('Period'), Format::date($statement['period']->starts_on).' – '.Format::date($statement['period']->ends_on), __('Currency'), $currency]);
            $add([]);
            $add([__('Release'), __('Track'), __('ISRC'), __('Units'), __('Revenue'), __('Artist share %'), __('Artist earnings')]);
            foreach ($statement['rows'] as $row) {
                $add([$row['release'], $row['title'], $row['isrc'] ?? '', $row['units'], self::cell($row['revenue']), self::cell($row['share'], 4), self::cell($row['amount'])]);
            }
            $add(['', __('Total'), '', $statement['totals']['units'], self::cell($statement['totals']['revenue']), '', self::cell($statement['totals']['amount'])]);
            $add([]);
            $add([__('Account')]);
            $add([__('Opening balance'), '', '', '', '', '', self::cell($statement['opening'])]);
            foreach ($statement['entries'] as $entry) {
                $add([Format::date($entry->entry_date), $entry->type->label(), $entry->description, $entry->reference ?? '', '', '', self::cell((string) $entry->amount)]);
            }
            $add([__('Closing balance'), '', '', '', '', '', self::cell($statement['closing'])]);

            $writer->close();
        }, $filename, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Rounded plain decimal string (no thousands separators) for spreadsheets.
     */
    protected static function cell(string $value, int $places = 2): string
    {
        return Decimal::round($value, $places);
    }
}
