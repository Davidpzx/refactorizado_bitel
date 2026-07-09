<?php

namespace App\Services\Facturacion;

use App\Exceptions\SunatSetupException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Manejo del certificado digital de SUNAT.
 *
 * Port de `gerencia/ajax_configurar_sunat.php` del legacy (`_cert_es_pem`,
 * `_openssl_disponible`, `_convertir_pfx_a_pem`).
 *
 * La API externa (Greenter) firma con PEM, pero SUNAT entrega el certificado
 * como `.pfx`/`.p12` cifrado, así que hay que convertirlo antes de subirlo.
 */
class CertificadoDigitalService
{
    /** Marcador de texto que distingue un PEM (texto) de un PFX (binario). */
    private const MARCADOR_PEM = '-----BEGIN';

    /**
     * El PEM que produce OpenSSL empieza con un bloque `Bag Attributes`, así que
     * el marcador no está en el byte 0: se busca en toda la cabecera.
     */
    private const BYTES_CABECERA = 4096;

    private const TIMEOUT_OPENSSL = 30;

    /** Rutas temporales creadas por este servicio; se borran en limpiarTemporales(). */
    /** @var list<string> */
    private array $temporales = [];

    /** Red de seguridad equivalente al `register_shutdown_function` del legacy. */
    public function __destruct()
    {
        $this->limpiarTemporales();
    }

    /**
     * Un certificado es PEM si su cabecera contiene el marcador `-----BEGIN`.
     * Un `.pfx`/`.p12` es binario y no lo tiene, por eso sirve para distinguirlos.
     */
    public function esPem(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $cabecera = @fread($handle, self::BYTES_CABECERA);
        fclose($handle);

        return is_string($cabecera) && str_contains($cabecera, self::MARCADOR_PEM);
    }

    public function opensslDisponible(): bool
    {
        $proceso = new Process(['openssl', 'version']);
        $proceso->setTimeout(self::TIMEOUT_OPENSSL);

        try {
            $proceso->run();
        } catch (Throwable) {
            return false;
        }

        return $proceso->isSuccessful() && stripos($proceso->getOutput(), 'OpenSSL') !== false;
    }

    /**
     * Convierte un `.pfx`/`.p12` protegido con contraseña a PEM sin cifrar.
     *
     * OpenSSL 3.x rechaza los PFX viejos de SUNAT (cifrados con RC2/3DES) salvo
     * que se le pase `-legacy`, por eso se reintenta con esa bandera. El orden
     * importa: `-legacy` no existe en OpenSSL 1.x, así que el intento limpio va
     * primero.
     *
     * La contraseña viaja por STDIN (`-passin stdin`), no por argv: así no queda
     * expuesta en la lista de procesos del servidor (`ps`). El legacy la pasaba
     * como `pass:...` en la línea de comandos.
     *
     * @return string Ruta del PEM temporal (se borra en limpiarTemporales()).
     *
     * @throws SunatSetupException Si la contraseña es incorrecta o el archivo no es un PFX válido.
     */
    public function convertirPfxAPem(string $pfxPath, string $password): string
    {
        $pemPath = $this->nuevoTemporal();

        foreach ([[], ['-legacy']] as $banderas) {
            $proceso = new Process(array_merge([
                'openssl', 'pkcs12',
                '-in', $pfxPath,
                '-out', $pemPath,
                '-nodes',
                '-passin', 'stdin',
            ], $banderas));

            $proceso->setInput($password);
            $proceso->setTimeout(self::TIMEOUT_OPENSSL);

            try {
                $proceso->run();
            } catch (Throwable) {
                continue;
            }

            if ($proceso->isSuccessful() && $this->pemUtilizable($pemPath)) {
                return $pemPath;
            }
        }

        throw new SunatSetupException(
            'La contraseña del certificado es incorrecta o el archivo no es un .pfx válido.',
            400,
        );
    }

    /**
     * Guarda el PEM en el disco privado, bajo `sunat/<ruc>/`.
     *
     * Ojo (operaciones): `storage/app/private` debe estar en un volumen
     * persistente; si no, un redeploy del contenedor borra el certificado y hay
     * que volver a subirlo. Nunca en `public/`: es una clave privada.
     *
     * @return string Ruta relativa dentro del disco `local`.
     */
    public function guardarPemPrivado(string $pemPath, string $ruc): string
    {
        $contenido = @file_get_contents($pemPath);

        if ($contenido === false) {
            throw new SunatSetupException('No se pudo leer el certificado convertido.', 500);
        }

        $destino = sprintf('sunat/%s/certificado.pem', $this->carpetaEmisor($ruc));

        Storage::disk('local')->put($destino, $contenido);

        return $destino;
    }

    /** Borra los temporales (contienen la clave privada del cliente). Idempotente. */
    public function limpiarTemporales(): void
    {
        foreach ($this->temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        $this->temporales = [];
    }

    /** @return list<string> */
    public function temporales(): array
    {
        return $this->temporales;
    }

    /** El `-out` de OpenSSL trunca, pero puede dejar un PEM parcial si el comando falla. */
    private function pemUtilizable(string $pemPath): bool
    {
        clearstatcache(true, $pemPath);

        return is_file($pemPath) && filesize($pemPath) > 0 && $this->esPem($pemPath);
    }

    private function nuevoTemporal(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'kyro_cert_');

        if ($ruta === false) {
            throw new SunatSetupException('No se pudo crear un archivo temporal para el certificado.', 500);
        }

        @chmod($ruta, 0600);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    /** El RUC llega de la BD, pero igual se sanea: va a formar parte de una ruta. */
    private function carpetaEmisor(string $ruc): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9]/', '', $ruc) ?? '';

        return $limpio !== '' ? $limpio : 'sin-ruc';
    }
}
