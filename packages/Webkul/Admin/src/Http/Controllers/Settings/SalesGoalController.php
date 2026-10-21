<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Webkul\Admin\DataGrids\Settings\SalesGoalDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Lead\Repositories\SalesGoalRepository;

/**
 * Metas de venta por período.
 *
 * Solo define el objetivo; el avance no se guarda, lo calcula
 * `Reporting\Metas` contra los leads cada vez que se pide el tablero.
 */
class SalesGoalController extends Controller
{
    public function __construct(protected SalesGoalRepository $salesGoalRepository) {}

    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(SalesGoalDataGrid::class)->process();
        }

        return view('admin::settings.metas.index');
    }

    public function create(): View
    {
        return view('admin::settings.metas.create', $this->opciones());
    }

    public function store(): RedirectResponse
    {
        $data = request()->validate($this->reglas());

        $this->salesGoalRepository->create($data + ['created_by' => auth()->guard('user')->id()]);

        session()->flash('success', 'Meta creada.');

        return redirect()->route('admin.settings.metas.index');
    }

    public function edit(int $id): View
    {
        return view('admin::settings.metas.edit', $this->opciones([
            'meta' => $this->salesGoalRepository->findOrFail($id),
        ]));
    }

    /**
     * Lo que necesitan los dos formularios.
     *
     * Los usuarios excluidos del reporte —la cuenta comodín «Agente» y los
     * roles que no venden— no se ofrecen: cargarle una meta a quien el tablero
     * nunca va a medir produce una barra en 0 % para siempre.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function opciones(array $extra = []): array
    {
        $rolesExcluidos = (array) config('reporting.ignored_roles', []);
        $usuariosExcluidos = (array) config('reporting.ignored_users', []);

        $usuarios = \Webkul\User\Models\UserProxy::modelClass()::query()
            ->whereNotIn('name', $usuariosExcluidos)
            ->when($rolesExcluidos, fn ($q) => $q->whereDoesntHave(
                'role',
                fn ($r) => $r->whereIn('name', $rolesExcluidos)
            ))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $extra + [
            'usuarios' => $usuarios,
            'metricas' => (array) config('reporting.goals.metricas', []),
            'periodos' => $this->periodos($extra['meta']->periodo ?? null),
            'meta'     => $extra['meta'] ?? null,
        ];
    }

    /**
     * Los períodos elegibles, como ['2026-10' => 'octubre 2026'].
     *
     * Un desplegable y no un `input type=month`: el componente de formulario del
     * CRM no soporta ese tipo —su `switch` no tiene el caso y tampoco un
     * `@default`, así que el campo no se dibujaba en absoluto—. Un select además
     * hace imposible cargar un período inválido, que es la mitad del trabajo de
     * la validación.
     *
     * Van 6 meses atrás y 12 adelante: atrás para cargar una meta olvidada del
     * trimestre pasado, adelante para planificar el año. Más atrás que eso es
     * reescribir historia.
     *
     * @return array<string, string>
     */
    protected function periodos(?string $incluir = null): array
    {
        $out = [];

        foreach (range(-6, 12) as $offset) {
            $fecha = now()->startOfMonth()->addMonths($offset);
            $out[$fecha->format('Y-m')] = $fecha->translatedFormat('F Y');
        }

        /*
         * El período de la meta que se está editando, aunque caiga fuera del
         * rango: si no, abrir una meta vieja la cambiaría de mes al guardar sin
         * que nadie lo pida.
         */
        if ($incluir && ! isset($out[$incluir])) {
            $fecha = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $incluir.'-01');

            $out = [$incluir => $fecha->translatedFormat('F Y')] + $out;
        }

        return $out;
    }

    public function update(int $id): RedirectResponse
    {
        $data = request()->validate($this->reglas($id));

        $this->salesGoalRepository->update($data, $id);

        session()->flash('success', 'Meta actualizada.');

        return redirect()->route('admin.settings.metas.index');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->salesGoalRepository->delete($id);

        return response()->json(['message' => 'Meta eliminada.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglas(?int $id = null): array
    {
        return [
            // Nulo es la meta del equipo, no un error de carga.
            'user_id'  => 'nullable|integer|exists:users,id',

            // 'YYYY-MM': el input type=month ya lo manda así.
            'periodo'  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],

            'metrica'  => ['required', Rule::in(array_keys((array) config('reporting.goals.metricas', [])))],

            'objetivo' => 'required|numeric|min:0',

            /*
            | La moneda solo tiene sentido en metas de monto, y se exige ahí
            | porque la cartera mezcla USD y Bs: una meta de «2.000.000» sin
            | moneda es ambigua por un factor de siete.
            */
            'moneda'   => 'nullable|required_if:metrica,monto|string|size:3',
        ];
    }
}
