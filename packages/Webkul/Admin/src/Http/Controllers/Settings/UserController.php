<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\DataGrids\Settings\UserDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\UserResource;
use Webkul\Admin\Notifications\User\Create as UserCreatedNotification;
use Webkul\Core\Services\AsignacionDeAsesor;
use Webkul\Distribuidor\Services\ZonasDelDistribuidor;
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

        // Para "Distribuye en": las ciudades, y qué rol lo hace aparecer.
        $ciudades = DB::table('lead_pipelines')->orderBy('name')->get(['id', 'name']);

        $rolDistribuidor = $roles->firstWhere('name', ZonasDelDistribuidor::ROL)?->id;

        // "Puede asignar asesor" aparece sólo para el Encargado, y sólo lo edita un administrador.
        $rolEncargado = $roles->firstWhere('name', AsignacionDeAsesor::ROL_ENCARGADO)?->id;

        $editaAsignar = auth()->guard('user')->user()?->role?->permission_type === 'all';

        return view('admin::settings.users.index', compact('roles', 'groups', 'ciudades', 'rolDistribuidor', 'rolEncargado', 'editaAsignar'));
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
            'puede_asignar'    => 'nullable|boolean',
        ]);

        $data = request()->all();

        if (
            isset($data['password'])
            && $data['password']
        ) {
            $data['password'] = bcrypt($data['password']);
        }

        $this->validarDistribuidor($data);

        $data = $this->permisoDeAsignar($data);

        Event::dispatch('settings.user.create.before');

        $admin = $this->userRepository->create($data);

        $admin->groups()->sync($data['groups'] ?? []);

        app(ZonasDelDistribuidor::class)->guardar((int) $admin->id, (int) $admin->role_id, (array) ($data['ciudades_distribucion'] ?? []));

        try {
            Mail::queue(new UserCreatedNotification($admin));
        } catch (\Exception $e) {
            report($e);
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

        $admin->setAttribute('ciudades_distribucion', app(ZonasDelDistribuidor::class)->ciudadesDe((int) $admin->id));

        return new JsonResponse([
            'data'   => $admin,
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
            'puede_asignar'    => 'nullable|boolean',
        ]);

        $data = request()->all();

        if (empty($data['password'])) {
            $data = Arr::except($data, ['password', 'confirm_password']);
        } else {
            $data['password'] = bcrypt($data['password']);
        }

        $authUser = auth()->guard('user')->user();

        if ($authUser->id == $id) {
            $data['status'] = true;
        }

        $this->validarDistribuidor($data);

        $data = $this->permisoDeAsignar($data);

        Event::dispatch('settings.user.update.before', $id);

        $admin = $this->userRepository->update($data, $id);

        $admin->groups()->sync($data['groups'] ?? []);

        app(ZonasDelDistribuidor::class)->guardar((int) $admin->id, (int) $admin->role_id, (array) ($data['ciudades_distribucion'] ?? []));

        Event::dispatch('settings.user.update.after', $admin);

        return new JsonResponse([
            'data'    => $admin,
            'message' => trans('admin::app.settings.users.index.update-success'),
        ]);
    }

    /**
     * Las reglas de un distribuidor: al menos una ciudad, permiso Grupo o Individual,
     * y su grupo es su empresa, no una ciudad.
     *
     * Los grupos sólo cuentan con permiso de grupo: el formulario los oculta en los
     * otros, pero el navegador puede mandarlos igual.
     */
    protected function validarDistribuidor(array $data): void
    {
        $errores = app(ZonasDelDistribuidor::class)->errores(
            isset($data['role_id']) ? (int) $data['role_id'] : null,
            $data['view_permission'] ?? null,
            (array) ($data['ciudades_distribucion'] ?? []),
            ($data['view_permission'] ?? null) === 'group' ? (array) ($data['groups'] ?? []) : [],
        );

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * "Puede asignar asesor" sólo existe para el rol Encargado: los administradores
     * siempre pueden y el resto nunca. Y sólo lo cambia un administrador, para que
     * nadie se lo dé a sí mismo si su rol llega a tener acceso a Usuarios.
     */
    protected function permisoDeAsignar(array $data): array
    {
        if (auth()->guard('user')->user()?->role?->permission_type !== 'all') {
            return Arr::except($data, ['puede_asignar']);
        }

        $data['puede_asignar'] = ! empty($data['puede_asignar'])
            && app(AsignacionDeAsesor::class)->esRolEncargado(isset($data['role_id']) ? (int) $data['role_id'] : null);

        return $data;
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
}
