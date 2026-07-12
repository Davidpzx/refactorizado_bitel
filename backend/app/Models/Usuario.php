<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    /** Roles canónicos del modelo de 4 roles (plan 16). */
    public const ROL_ADMINISTRADOR = 'administrador';
    public const ROL_GERENTE       = 'gerente';
    public const ROL_JEFE_TIENDA   = 'jefe_tienda';
    public const ROL_AGENTE        = 'agente';

    /** @var array<int, string> Los 4 roles canónicos, en orden de jerarquía descendente. */
    public const ROLES = [
        self::ROL_ADMINISTRADOR,
        self::ROL_GERENTE,
        self::ROL_JEFE_TIENDA,
        self::ROL_AGENTE,
    ];

    /**
     * Alias legacy → rol canónico. Mantiene retrocompatibles las sesiones/tokens
     * vivos y las factories/tests que aún crean usuarios con los roles viejos.
     * 'vendedor' se agrupa históricamente con 'tienda' (ver EnsureRole legacy).
     *
     * @var array<string, string>
     */
    private const ALIAS_ROLES = [
        'admin'    => self::ROL_ADMINISTRADOR,
        'tienda'   => self::ROL_JEFE_TIENDA,
        'vendedor' => self::ROL_JEFE_TIENDA,
    ];

    protected $table = 'usuarios';
    public $timestamps = false;

    protected $fillable = [
        'nombre', 'email', 'password', 'rol', 'tienda_id', 'agente_id', 'activo', 'formato_ticket',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'activo'     => 'boolean',
        'tiene_bcp'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    public function scopePorRol(Builder $query, string $rol): Builder
    {
        return $query->where('rol', $rol);
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }

    /**
     * Normaliza un valor de rol (canónico o alias legacy) a su forma canónica.
     * Un rol desconocido se devuelve tal cual (en minúsculas) para no ocultar datos raros.
     */
    public static function normalizarRol(?string $rol): string
    {
        $rol = strtolower(trim((string) $rol));

        return self::ALIAS_ROLES[$rol] ?? $rol;
    }

    /** Rol canónico de este usuario (resuelve alias legacy). */
    public function rolCanonico(): string
    {
        return self::normalizarRol($this->rol);
    }

    /** ¿Este usuario tiene el rol indicado? Compara en forma canónica (acepta alias en ambos lados). */
    public function esRol(string $rol): bool
    {
        return $this->rolCanonico() === self::normalizarRol($rol);
    }

    /** ¿Este usuario tiene alguno de los roles indicados? Comparación canónica. */
    public function tieneAlgunRol(string ...$roles): bool
    {
        $actual = $this->rolCanonico();

        foreach ($roles as $rol) {
            if ($actual === self::normalizarRol($rol)) {
                return true;
            }
        }

        return false;
    }

    /** Atajo de jerarquía: el administrador está por encima de cualquier check de rol. */
    public function esAdministrador(): bool
    {
        return $this->rolCanonico() === self::ROL_ADMINISTRADOR;
    }
}
