<?php

namespace App\Support;

use Illuminate\Http\Request;

final class Paginacion
{
    public const MAXIMO_POR_PAGINA = 100;

    public static function desde(Request $request, int $porDefecto): int
    {
        return min(
            max((int) $request->input('per_page', $porDefecto), 1),
            self::MAXIMO_POR_PAGINA
        );
    }
}
