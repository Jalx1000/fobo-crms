{!! view_render_event('admin.products.view.attributes.before', ['product' => $product]) !!}

<div class="flex w-full flex-col gap-4 border-b border-gray-200 p-4 dark:border-gray-800 dark:text-white">
    <x-admin::accordion  class="select-none !border-none">
        <x-slot:header class="!p-0">
            <h4 class="font-semibold dark:text-white">
                @lang('admin::app.products.view.attributes.about-product')
            </h4>
        </x-slot>

        <x-slot:content class="mt-4 !px-0 !pb-0">
            {!! view_render_event('admin.products.view.attributes.view.before', ['product' => $product]) !!}
    
            <!-- Attributes Listing -->
            <div>
                <?php
                    $all = app('Webkul\\Attribute\\Repositories\\AttributeRepository')
                        ->findWhere(['entity_type' => 'products'])
                        ->sortBy('sort_order');

                    $defaultCodes = ['sku', 'price', 'quantity'];

                    // type_service_2 ("Linea") es redundante con la columna real
                    // products.specialty_id ("Especialidad"); se oculta del EAV.
                    $hiddenCodes = ['type_service_2'];

                    $defaultAttributes = $all->filter(fn($attr) => in_array(strtolower($attr->code), $defaultCodes));
                    $customAttributes  = $all->reject(fn($attr) => in_array(strtolower($attr->code), array_merge($defaultCodes, $hiddenCodes)));
                ?>

                <x-admin::attributes.view
                    :custom-attributes="$defaultAttributes"
                    :entity="$product"
                    :url="route('admin.products.update', $product->id)"   
                    :allow-edit="bouncer()->hasPermission('products.edit')"
                />

                <x-admin::attributes.view
                    :custom-attributes="$customAttributes"
                    :entity="$product"
                    :url="route('admin.products.update', $product->id)"   
                    :allow-edit="bouncer()->hasPermission('products.edit')"
                />
            </div>
            
            {!! view_render_event('admin.products.view.attributes.view.after', ['product' => $product]) !!}
        </x-slot>
    </x-admin::accordion>
</div>

{!! view_render_event('admin.products.view.attributes.before', ['product' => $product]) !!}
