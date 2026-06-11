<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'dni'           => ['required', 'string', 'size:8', 'regex:/^\d{8}$/', 'unique:agentes,dni'],
            'nombres'       => ['required', 'string', 'max:200'],
            'tienda_base'   => ['required', 'string', 'max:10'],
            'pin_seguridad' => ['required', 'string', 'digits:4'],
            'sueldo_base'   => ['required', 'numeric', 'min:0'],
            'estado'        => ['sometimes', 'in:ACTIVO,INACTIVO,BAJA'],
            'hora_ingreso'  => ['nullable', 'string', 'max:8'],
            'hora_salida'   => ['nullable', 'string', 'max:8'],
            'dia_pago'      => ['nullable', 'integer', 'min:1', 'max:31'],
            'dia_descanso'  => ['nullable', 'string', 'max:20'],
            'fecha_ingreso' => ['required', 'date'],
            'correo'        => ['nullable', 'email', 'max:120'],
            'telefono'      => ['nullable', 'string', 'max:15'],
            'direccion'     => ['nullable', 'string', 'max:300'],
            'es_gerencia'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.size'          => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.regex'         => 'El DNI debe contener solo dígitos.',
            'dni.unique'        => 'Ya existe un agente registrado con ese DNI.',
            'pin_seguridad.digits' => 'El PIN debe tener exactamente 4 dígitos.',
        ];
    }
}
