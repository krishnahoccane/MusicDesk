<?php

namespace App\Http\Controllers;

use App\Services\BulkUpload\BulkUploadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkUploadTemplateController extends Controller
{
    public function __invoke(Request $request, string $type, string $format, BulkUploadService $bulk): StreamedResponse
    {
        abort_unless($bulk->allows($request->user(), $type), 403);

        return $bulk->template($type, $format);
    }
}
