<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { width: 80%; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .header { font-size: 18px; font-weight: bold; border-bottom: 2px solid #BA2831; padding-bottom: 10px; margin-bottom: 20px; color: #BA2831; }
        .detail-row { margin-bottom: 10px; }
        .label { font-weight: bold; width: 150px; display: inline-block; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .footer { margin-top: 30px; font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            Nuevo Pedido Confirmado #{{ $lead->id }}
        </div>

        <div class="detail-row">
            <span class="label">Contacto:</span> {{ $details['customer_name'] }}
        </div>
        <div class="detail-row">
            <span class="label">Fecha Confirmación:</span> {{ $details['confirmation_date'] }}
        </div>
        <div class="detail-row">
            <span class="label">Dirección Entrega:</span> {{ $details['delivery_address'] }}
        </div>

        <h3>Detalle de Productos</h3>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Precio</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lead->products as $product)
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->quantity }}</td>
                    <td>{{ core()->formatBasePrice($product->price) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="footer">
            Este es un correo automático generado por el sistema CRM Kohlberg.
        </div>
    </div>
</body>
</html>
