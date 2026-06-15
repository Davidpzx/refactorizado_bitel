<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Agente extends Model
{
    protected $table = 'agentes';
    public $timestamps = false;

    protected $fillable = [
        'dni', 'nombres', 'apellidos', 'tienda_base', 'hora_ingreso', 'hora_salida',
        'sueldo_base', 'dia_pago', 'estado', 'dia_descanso',
        'pin_seguridad', 'fecha_ingreso', 'correo', 'telefono', 'direccion',
        'es_gerencia', 'permiso_largo', 'fecha_retorno',
        'fecha_prueba_inicio', 'fecha_prueba_fin', 'fecha_nacimiento',
        'lugar_nacimiento', 'formacion_academica', 'carga_familiar',
        'experiencia_laboral', 'contactos_emergencia', 'sistema_pension',
        'nombre_afp', 'numero_cuspp', 'grupo_sanguineo', 'alergias',
        'antecedentes_penales', 'antecedentes_policial', 'antecedentes_judicial',
    ];

    protected $hidden = ['pin_seguridad', 'hash_dispositivo', 'hash_facial'];

    protected $casts = [
        'sueldo_base'           => 'decimal:2',
        'fecha_ingreso'         => 'date',
        'fecha_retorno'         => 'date',
        'fecha_baja'            => 'date',
        'permiso_largo'         => 'boolean',
        'antecedentes_penales'  => 'boolean',
        'creado_en'             => 'datetime',
        'formacion_academica'   => 'array',
        'carga_familiar'        => 'array',
        'experiencia_laboral'   => 'array',
        'contactos_emergencia'  => 'array',
        'antecedentes_penales'  => 'boolean',
        'antecedentes_policial' => 'boolean',
        'antecedentes_judicial' => 'boolean',
    ];

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    public function adelantos(): HasMany
    {
        return $this->hasMany(Adelanto::class);
    }

    public function reportes(): HasMany
    {
        return $this->hasMany(Reporte::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', 'ACTIVO');
    }

    public function scopePorTienda($query, string $tienda)
    {
        return $query->where('tienda_base', $tienda);
    }

    public function scopeBuscar($query, string $termino)
    {
        return $query->where('dni', 'like', "%{$termino}%")
                     ->orWhere('nombres', 'like', "%{$termino}%");
    }

    public function verificarPin(string $pin): bool
    {
        $pinGuardado = trim((string) $this->pin_seguridad);

        if ($pinGuardado === '') {
            return false;
        }

        if (! Hash::isHashed($pinGuardado)) {
            return hash_equals($pinGuardado, $pin);
        }

        return Hash::check($pin, $pinGuardado);
    }
}
