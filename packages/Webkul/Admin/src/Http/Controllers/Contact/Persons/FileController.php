<?php

namespace Webkul\Admin\Http\Controllers\Contact\Persons;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\EntityFiles;
use Webkul\Contact\Repositories\PersonRepository;

class FileController extends Controller
{
    public function __construct(
        protected PersonRepository $personRepository,
        protected EntityFiles $files,
    ) {}

    /**
     * Every file ever exchanged with this contact — their own activities plus
     * everything belonging to any of their leads.
     */
    public function index(int $id): JsonResponse
    {
        $person = $this->personRepository->findOrFail($id);

        return response()->json(['files' => $this->files->forPerson($person->id)]);
    }
}
