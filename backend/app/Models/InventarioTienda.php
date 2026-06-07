<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioTienda extends Model
{
    protected $table = 'inventario_tiendas';

    public $timestamps = false;

    protected $fillable = [
        'tienda_id',
        'producto_nombre',
        'tipo',
        'imei_serial',
        'precio_costo',
        'precio_minimo',
        'precio_normal',
        'cantidad',
        'estado',
        'registrado_por',
        'fecha_registro',
        'fecha_venta',
        'vendido_por_id',
        'reporte_venta_id',
        'comision_especial',
    ];

    protected $casts = [
        'precio_costo'      => 'decimal:2',
        'precio_minimo'     => 'decimal:2',
        'precio_normal'     => 'decimal:2',
        'comision_especial' => 'decimal:2',
        'cantidad'          => 'integer',
    ];

    public function scopeBuscar($query, string $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('producto_nombre', 'like', "%{$termino}%")
              ->orWhere('imei_serial', 'like', "%{$termino}%");
        });
    }

    public function scopePorTienda($query, string $tienda)
    {
        return $query->where('tienda_id', $tienda);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }
}
