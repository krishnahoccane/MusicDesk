<?php

namespace App\Livewire\Reports;

use App\Models\ExportLog;
use App\Reports\ReportRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Reports: the reports this user may run, grouped by area.
 */
class Index extends Component
{
    public function render(ReportRegistry $registry): View
    {
        $user = auth()->user();

        return view('livewire.reports.index', [
            'groups' => $registry->groupedFor($user),
            'exports' => $user->can('reports.export')
                ? ExportLog::query()->where('user_id', $user->id)->latest('id')->limit(8)->get()
                : collect(),
            'registry' => $registry,
        ])->title(__('Reports'))->layoutData(['breadcrumbs' => [['label' => __('Business')], ['label' => __('Reports')]]]);
    }
}
