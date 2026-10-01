<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\DataGrids\Settings\UserDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\UserResource;
use Webkul\Admin\Notifications\User\Create as UserCreatedNotification;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\Responsables;
use Webkul\User\Repositories\GroupRepository;
use Webkul\User\Repositories\RoleRepository;
use Webkul\User\Repositories\UserRepository;

class UserController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected UserRepository $userRepository,
        protected GroupRepository $groupRepository,
        protected RoleRepository $roleRepository
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(UserDataGrid::class)->process();
        }

        $roles = $this->roleRepository->all();

        $groups = $this->groupRepository->all();

        // Para la grilla «Atiende» del modal: qué producto en qué ciudad. Los
        // productos son las líneas de Imprimir (cuatro), no un catálogo largo.
        $productos = app(ProductRepository::class)->getModel()->newQuery()->orderBy('id')->get(['id', 'name']);

        $ciudades = Responsables::ciudades();

        return view('admin::settings.users.index', compact('roles', 'groups', 'productos', 'ciudades'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): View|JsonResponse
    {
        $this->validate(request(), [
            'email'            => 'required|email|unique:users,email',
            'name'             => 'required',
            'password'         => 'nullable',
            'confirm_password' => 'nullable|required_with:password|same:password',
            'role_id'          => 'required',
            'status'           => 'boolean|in:0,1',
            'view_permission'  => 'string|in:global,group,individual',
            'horario'          => 'nullable|string',
            'cargo'            => 'nullable|string|max:120',
            'telefono'         => 'nullable|string|max:30',
            'atiende'          => 'nullable|array',
            'atiende.*'        => 'nullable|array',
            'atiende.*.*'      => 'string|max:60',
            'firma'            => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $data = request()->except('atiende');

        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'telefono')) {
            unset($data['telefono']);
        }

        if (
            isset($data['password'])
            && $data['password']
        ) {
            $data['password'] = bcrypt($data['password']);
        }

        // El archivo no va por el repositorio: se guarda aparte, con el id.
        unset($data['firma']);

        Event::dispatch('settings.user.create.before');

        $admin = $this->userRepository->create($data);

        // Después de crear: la carpeta lleva el id, que recién existe acá.
        if ($firma = $this->guardarFirma($admin)) {
            $admin->update(['firma' => $firma]);
        }

        $admin->groups()->sync($data['groups'] ?? []);

        try {
            Mail::queue(new UserCreatedNotification($admin));
        } catch (\Exception $e) {
            report($e);
        }

        // Qué atiende: la matriz producto × ciudad, guardada aparte del
        // usuario porque vive en su propia tabla (ver Responsables).
        if (request()->has('atiende')) {
            app(Responsables::class)->guardarDe($admin->id, (array) request()->input('atiende', []));
        }

        Event::dispatch('settings.user.create.after', $admin);

        return new JsonResponse([
            'data'    => $admin,
            'message' => trans('admin::app.settings.users.index.create-success'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View|JsonResponse
    {
        $admin = $this->userRepository->with(['role', 'groups'])->findOrFail($id);

        $productos = app(ProductRepository::class)->getModel()->newQuery()->orderBy('id')->get(['id']);

        return new JsonResponse([
            'data' => $admin->toArray() + [
                'atiende' => app(Responsables::class)->matrizDe($admin->id, $productos),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(int $id): JsonResponse
    {
        $this->validate(request(), [
            'email'            => 'required|email|unique:users,email,'.$id,
            'name'             => 'required|string',
            'password'         => 'nullable|string|min:6',
            'confirm_password' => 'nullable|required_with:password|same:password',
            'role_id'          => 'required|integer|exists:roles,id',
            'status'           => 'nullable|boolean|in:0,1',
            'view_permission'  => 'required|string|in:global,group,individual',
            'horario'          => 'nullable|string',
            'cargo'            => 'nullable|string|max:120',
            'telefono'         => 'nullable|string|max:30',
            'atiende'          => 'nullable|array',
            'atiende.*'        => 'nullable|array',
            'atiende.*.*'      => 'string|max:60',
            'firma'            => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'quitar_firma'     => 'nullable|boolean',
        ]);

        $data = request()->except('atiende', 'quitar_firma');

        // Hasta que corra la migración del round robin la columna no existe;
        // que falte un teléfono no puede impedir editar el resto del usuario.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'telefono')) {
            unset($data['telefono']);
        }

        if (empty($data['password'])) {
            $data = Arr::except($data, ['password', 'confirm_password']);
        } else {
            $data['password'] = bcrypt($data['password']);
        }

        $authUser = auth()->guard('user')->user();

        if ($authUser->id == $id) {
            $data['status'] = true;
        }

        Event::dispatch('settings.user.update.before', $id);

        /*
        | La firma solo se toca si vino un archivo o si la quitaron. El modal
        | manda FormData con todos los campos, y `firma` ausente no significa
        | «borrala», significa que no la cambiaron: pisarla con null cada vez
        | que editan el cargo dejaría a los asesores sin firma sin que nadie
        | entienda por qué. Quitarla es explícito (`quitar_firma`), y una nueva
        | subida en el mismo guardado gana.
        */
        unset($data['firma']);

        $user = $this->userRepository->find($id);

        if ($firma = $this->guardarFirma($user)) {
            $data['firma'] = $firma;
        } elseif (request()->boolean('quitar_firma') && $user?->firma) {
            Storage::delete($user->firma);

            $data['firma'] = null;
        }

        $admin = $this->userRepository->update($data, $id);

        $admin->groups()->sync($data['groups'] ?? []);

        // `has` y no `filled`: un formulario con todas las casillas
        // desmarcadas manda `atiende` vacío y eso significa «ya no atiende
        // nada», mientras que un cliente que no manda el campo no lo toca.
        if (request()->has('atiende')) {
            app(Responsables::class)->guardarDe($admin->id, (array) request()->input('atiende', []));
        }

        Event::dispatch('settings.user.update.after', $admin);

        return new JsonResponse([
            'data'    => $admin,
            'message' => trans('admin::app.settings.users.index.update-success'),
        ]);
    }

    /**
     * Search user results.
     */
    public function search(): JsonResource
    {
        $users = $this->userRepository
            ->pushCriteria(app(RequestCriteria::class))
            ->all();

        return UserResource::collection($users);
    }

    /**
     * Destroy specified user.
     */
    public function destroy(int $id): JsonResponse
    {
        if ($this->userRepository->count() == 1) {
            return new JsonResponse([
                'message' => trans('admin::app.settings.users.index.last-delete-error'),
            ], 400);
        }

        try {
            Event::dispatch('user.admin.delete.before', $id);

            $this->userRepository->delete($id);

            Event::dispatch('user.admin.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.settings.users.index.delete-success'),
            ], 200);
        } catch (\Exception $e) {
        }

        return new JsonResponse([
            'message' => trans('admin::app.settings.users.index.delete-failed'),
        ], 500);
    }

    /**
     * Mass Update the specified resources.
     */
    public function massUpdate(MassUpdateRequest $massDestroyRequest): JsonResponse
    {
        $count = 0;

        $users = $this->userRepository->findWhereIn('id', $massDestroyRequest->input('indices'));

        foreach ($users as $users) {
            if (auth()->guard('user')->user()->id == $users->id) {
                continue;
            }

            Event::dispatch('settings.user.update.before', $users->id);

            $this->userRepository->update([
                'status' => $massDestroyRequest->input('value'),
            ], $users->id);

            Event::dispatch('settings.user.update.after', $users->id);

            $count++;
        }

        if (! $count) {
            return response()->json([
                'message' => trans('admin::app.settings.users.index.mass-update-failed'),
            ], 400);
        }

        return response()->json([
            'message' => trans('admin::app.settings.users.index.mass-update-success'),
        ]);
    }

    /**
     * Mass Delete the specified resources.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $count = 0;

        $users = $this->userRepository->findWhereIn('id', $massDestroyRequest->input('indices'));

        foreach ($users as $user) {
            if (auth()->guard('user')->user()->id == $user->id) {
                continue;
            }

            Event::dispatch('settings.user.delete.before', $user->id);

            $this->userRepository->delete($user->id);

            Event::dispatch('settings.user.delete.after', $user->id);

            $count++;
        }

        if (! $count) {
            return response()->json([
                'message' => trans('admin::app.settings.users.index.mass-delete-failed'),
            ], 400);
        }

        return response()->json([
            'message' => trans('admin::app.settings.users.index.mass-delete-success'),
        ]);
    }

    /**
     * Guarda la firma subida y devuelve su ruta, o null si no vino ninguna.
     *
     * Misma carpeta y disco que el avatar (`admins/{id}`), con un nombre propio
     * para que no se pisen. La anterior se borra: una firma vieja suelta en el
     * disco no sirve para nada y ocupa lugar.
     */
    protected function guardarFirma($user): ?string
    {
        if (! $user || ! request()->hasFile('firma')) {
            return null;
        }

        if ($user->firma) {
            Storage::delete($user->firma);
        }

        return request()->file('firma')->store('admins/'.$user->id.'/firma');
    }
}
