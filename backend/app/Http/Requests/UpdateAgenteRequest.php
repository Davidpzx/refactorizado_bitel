<?php

namespace App\Http\Requests;

use App\Models\Agente;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAgenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $agenteActual = $this->route('agente');
        $idActual     = $agenteActual instanceof Agente ? $agenteActual->id : $agenteActual;

        return [
            'nombres'       => ['sometimes', 'string', 'max:200', function ($attribute, $value, $fail) use ($idActual) {
                $existe = Agente::whereRaw('LOWER(TRIM(nombres)) = ?', [mb_strtolower(trim($value))])
                    ->when($idActual, fn ($q) => $q->where('id', '!=', $idActual))
                    ->exists();
                if ($existe) {
                    $fail('Ya existe un agente registrado con ese nombre (verifica que no sea un duplicado).');
                }
            }],
            'tienda_base'   => ['sometimes', 'string', 'max:10'],
            'pin_seguridad' => ['sometimes', 'nullable', 'string', 'digits:4'],
            'sueldo_base'   => ['sometimes', 'numeric', 'min:0'],
            'estado'        => ['sometimes', 'in:ACTIVO,INACTIVO,BAJA'],
            'hora_ingreso'  => ['nullable', 'string', 'max:8'],
            'hora_salida'   => ['nullable', 'string', 'max:8'],
            'dia_pago'      => ['nullable', 'integer', 'min:1', 'max:31'],
            'dia_descanso'  => ['nullable', 'string', 'max:20'],
            'fecha_ingreso' => ['sometimes', 'date'],
            'correo'        => ['nullable', 'email', 'max:120'],
            'telefono'      => ['nullable', 'string', 'max:15'],
            'direccion'     => ['nullable', 'string', 'max:60'],
            'es_gerencia'   => ['sometimes', 'boolean'],
            'permiso_largo' => ['sometimes', 'boolean'],
            'fecha_retorno' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin_seguridad.digits' => 'El PIN debe tener exactamente 4 dígitos.',
            'direccion.max'        => 'Máximo 60 caracteres.',
        ];
    }
}
