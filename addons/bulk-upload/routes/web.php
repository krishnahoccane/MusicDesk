<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\BulkUpload\Http\TemplateController;
use LabelDesk\Addons\BulkUpload\Livewire\Index;
use LabelDesk\Addons\BulkUpload\Livewire\Settings;

// Catalog → Bulk upload (who may use it: BulkUploadService::allows)
Route::get('bulk-upload', Index::class)->middleware('can:bulk-upload')->name('bulk-upload.index');
Route::get('bulk-upload/template/{type}/{format}', TemplateController::class)
    ->whereIn('type', ['artists', 'releases'])->whereIn('format', ['csv', 'xlsx'])
    ->middleware(['can:bulk-upload', 'throttle:30,1'])->name('bulk-upload.template');

// Settings → Bulk upload (who may use it)
Route::get('settings/bulk-upload', Settings::class)->middleware('permission:settings.manage')->name('bulk-upload.settings');
