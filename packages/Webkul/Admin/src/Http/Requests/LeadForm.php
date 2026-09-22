<?php

namespace Webkul\Admin\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Core\Contracts\Validations\Decimal;

class LeadForm extends FormRequest
{
    /**
     * @var array
     */
    protected $rules = [];

    /**
     * Create a new form request instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected AttributeValueRepository $attributeValueRepository
    ) {}

    /**
     * Determine if the product is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    /**
     * Descarta las filas de producto que no tienen producto elegido.
     *
     * El formulario deja agregar filas vacías, y quitar el producto de una fila
     * ya cargada la deja igual de vacía (el lookup emite un objeto vacío al
     * limpiarse). Sin esto la validación cortaba el guardado con
     * "The products.product_0.product_id field is required" — un mensaje sobre
     * un campo OCULTO, que el asesor no puede ver ni corregir: el pedido no se
     * guardaba y no había forma de saber por qué.
     *
     * Una fila sin producto no es un producto, así que se ignora. Las filas que
     * sí tienen producto siguen validándose igual que antes.
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($productos = $this->input('products'))) {
            return;
        }

        $conProducto = array_filter(
            $productos,
            fn ($fila) => ! empty($fila['product_id'])
        );

        $this->merge([
            'products' => $conProducto,
        ]);
    }

    public function rules()
    {
        // $allData = $this->all();
        // var_dump($allData);
        // exit();
        foreach (['leads', 'persons'] as $key => $entityType) {
            $attributes = $this->attributeRepository->scopeQuery(function ($query) use ($entityType) {
                $attributeCodes = $entityType == 'persons'
                    ? array_keys(request('person') ?? [])
                    : array_keys(request()->all());

                $query = $query->whereIn('code', $attributeCodes)
                    ->where('entity_type', $entityType);

                if (request()->has('quick_add')) {
                    $query = $query->where('quick_add', 1);
                }

                return $query;
            })->get();

            foreach ($attributes as $attribute) {
                if ($entityType == 'persons') {
                    $attribute->code = 'person.'.$attribute->code;
                }

                $validations = [];

                if ($attribute->type == 'boolean') {
                    continue;
                } elseif ($attribute->type == 'address') {
                    if (! $attribute->is_required) {
                        continue;
                    }

                    $validations = [
                        $attribute->code.'.address'  => 'required',
                        $attribute->code.'.country'  => 'required',
                        $attribute->code.'.state'    => 'required',
                        $attribute->code.'.city'     => 'required',
                        $attribute->code.'.postcode' => 'required',
                    ];
                } elseif ($attribute->type == 'email') {
                    $isPerson = $entityType == 'persons';
                    $isRequired = $isPerson ? false : $attribute->is_required;

                    $validations = [
                        $attribute->code              => [$isRequired ? 'required' : 'nullable'],
                        $attribute->code.'.*.value'   => [$isRequired ? 'required' : 'nullable', 'email'],
                        $attribute->code.'.*.label'   => $isRequired ? 'required' : 'nullable',
                    ];
                } elseif ($attribute->type == 'phone') {
                    $validations = [
                        $attribute->code              => [$attribute->is_required ? 'required' : 'nullable'],
                        $attribute->code.'.*.value'   => [$attribute->is_required ? 'required' : 'nullable'],
                        $attribute->code.'.*.label'   => $attribute->is_required ? 'required' : 'nullable',
                    ];
                } else {
                    $validations[$attribute->code] = [$attribute->is_required ? 'required' : 'nullable'];

                    if ($attribute->type == 'text' && $attribute->validation) {
                        array_push($validations[$attribute->code],
                            $attribute->validation == 'decimal'
                            ? new Decimal
                            : $attribute->validation
                        );
                    }

                    if ($attribute->type == 'price') {
                        array_push($validations[$attribute->code], new Decimal);
                    }
                }

                if ($attribute->is_unique) {
                    array_push($validations[in_array($attribute->type, ['email', 'phone'])
                        ? $attribute->code.'.*.value'
                        : $attribute->code
                    ], function ($field, $value, $fail) use ($attribute, $entityType) {
                        if (! $this->attributeValueRepository->isValueUnique(
                            $entityType == 'persons' ? request('person.id') : $this->id,
                            $attribute->entity_type,
                            $attribute,
                            request($field)
                        )
                        ) {
                            $fail('The value has already been taken.');
                        }
                    });
                }

                $this->rules = array_merge($this->rules, $validations);
            }
        }

        $this->rules['expected_close_date'] = [
            'date_format:Y-m-d',
            'after:'.Carbon::yesterday()->format('Y-m-d'),
        ];

        return [
            ...$this->rules,
            'products'              => 'array',
            'products.*.product_id' => 'sometimes|required|exists:products,id',
            'products.*.name'       => 'required_with:products.*.product_id',
            'products.*.price'      => 'required_with:products.*.product_id',
            'products.*.quantity'   => 'required_with:products.*.product_id',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'products.*.product_id.exists'      => trans('admin::app.leads.selected-product-not-exist'),
            'products.*.name.required_with'     => trans('admin::app.leads.product-name-required'),
            'products.*.price.required_with'    => trans('admin::app.leads.product-price-required'),
            'products.*.quantity.required_with' => trans('admin::app.leads.product-quantity-required'),
        ];
    }
}
