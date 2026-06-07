<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombres'       => ['sometimes', 'string', 'max:200'],
            'tienda_base'   => ['sometimes', 'string', 'max:10'],
            'pin_seguridad' => ['sometimes', 'nullable', 'string', 'min:4', 'max:8'],
            'sueldo_base'   => ['sometimes', 'numeric', 'min:0'],
            'estado'        => ['sometimes', 'in:ACTIVO,INACTIVO,BAJA'],
            'hora_ingreso'  => ['nullable', 'string', 'max:8'],
            'hora_salida'   => ['nullable', 'string', 'max:8'],
            'dia_pago'      => ['nullable', 'integer', 'min:1', 'max:31'],
            'dia_descanso'  => ['nullable', 'string', 'max:20'],
            'fecha_ingreso' => ['sometimes', 'date'],
            'correo'        => ['nullable', 'email', 'max:120'],
            'telefono'      => ['nullable', 'string', 'max:15'],
            'direccion'     => ['nullable', 'string', 'max:300'],
            'es_gerencia'   => ['sometimes', 'boolean'],
            'permiso_largo' => ['sometimes', 'boolean'],
            'fecha_retorno' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin_seguridad.min' => 'El PIN debe tener mínimo 4 caracteres.',
            'pin_seguridad.max' => 'El PIN no puede superar 8 caracteres.',
        ];
    }
}
