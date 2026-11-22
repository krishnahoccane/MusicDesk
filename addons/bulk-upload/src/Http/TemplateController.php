<?php

namespace LabelDesk\Addons\BulkUpload\Http;

use Illuminate\Http\Request;
use LabelDesk\Addons\BulkUpload\Services\BulkUploadService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateController
{
    public function __invoke(Request $request, string $type, string $format, BulkUploadService $bulk): StreamedResponse
    {
        abort_unless($bulk->allows($request->user(), $type), 403);

        return $bulk->template($type, $format);
    }
}
