<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Definitions\ArtistBalancesReport;
use App\Reports\Definitions\CatalogueGapsReport;
use App\Reports\Definitions\ContractsEndingReport;
use App\Reports\Definitions\DeliveriesReport;
use App\Reports\Definitions\PaymentsReport;
use App\Reports\Definitions\ReleasesReport;
use App\Reports\Definitions\RevenueReport;
use App\Reports\Definitions\StoreSummaryReport;

class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [
        ReleasesReport::class,
        CatalogueGapsReport::class,
        DeliveriesReport::class,
        StoreSummaryReport::class,
        RevenueReport::class,
        ArtistBalancesReport::class,
        PaymentsReport::class,
        ContractsEndingReport::class,
    ];

    /**
     * @return array<string, Report>
     */
    public function all(): array
    {
        $reports = [];
        foreach (self::REPORTS as $class) {
            $report = app($class);
            $reports[$report->key()] = $report;
        }

        return $reports;
    }

    public function find(string $key): ?Report
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Reports the user may open, grouped, with a flag for plan-locked ones.
     *
     * @return array<string, list<array{report: Report, locked: bool}>>
     */
    public function groupedFor(User $user): array
    {
        $groups = [];
        foreach ($this->all() as $report) {
            if ($report->permitted($user)) {
                $groups[$report->group()][] = ['report' => $report, 'locked' => ! $report->licensed()];
            }
        }

        return $groups;
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'catalogue' => __('Catalogue'),
            'distribution' => __('Distribution'),
            'royalties' => __('Royalties & payments'),
            'contracts' => __('Contracts'),
            default => ucfirst($group),
        };
    }
}
