<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\EntityFiles;
use Webkul\Lead\Repositories\LeadRepository;

class FileController extends Controller
{
    public function __construct(
        protected LeadRepository $leadRepository,
        protected EntityFiles $files,
    ) {}

    /**
     * Files of this deal only. The contact's tab is where the whole history is.
     */
    public function index(int $id): JsonResponse
    {
        $lead = $this->leadRepository->findOrFail($id);

        return response()->json(['files' => $this->files->forLead($lead->id)]);
    }
}
