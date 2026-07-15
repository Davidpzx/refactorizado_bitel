<?php

namespace App\Services\WhatsApp;

interface WhatsAppProvider
{
    /** Crea la instancia en el proveedor y devuelve datos crudos (incluye QR si el proveedor lo entrega de una). */
    public function crearInstancia(string $nombreInstancia): array;

    /** Devuelve el QR como string base64 (data URI) para mostrar en el frontend. */
    public function obtenerQR(string $nombreInstancia): string;

    /** 'conectada' | 'desconectada' | 'qr_pendiente' */
    public function estadoInstancia(string $nombreInstancia): string;

    public function eliminarInstancia(string $nombreInstancia): void;

    /** Muestra "escribiendo..." en el chat durante $delayMs milisegundos. */
    public function enviarPresencia(string $nombreInstancia, string $jid, int $delayMs): void;

    /** Envia un menu de lista nativo. Opciones: [['id'=>..,'texto'=>..], ...]. Devuelve [] en fallo (el caller hace fallback a texto). */
    public function enviarLista(string $nombreInstancia, string $jid, string $titulo, array $opciones): array;

    public function enviarTexto(string $nombreInstancia, string $jid, string $texto): array;

    public function enviarMedia(string $nombreInstancia, string $jid, string $mediaUrl, string $tipo, ?string $caption): array;
}