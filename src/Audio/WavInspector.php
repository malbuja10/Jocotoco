<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

/**
 * Lee la cabecera de un WAV para conocer sus caracteristicas reales.
 *
 * El contrato del monitor declara frecuencia, canales, profundidad y
 * duracion en el JSON del evento, pero esos valores los calcula el propio
 * dispositivo: aqui se obtienen del archivo para poder validarlos y para
 * guardar lo que el audio realmente contiene.
 */
final class WavInspector
{
    public function inspect(string $path): ?WavInfo
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $cabecera = (string) fread($handle, 12);
            if (strlen($cabecera) < 12
                || !str_starts_with($cabecera, 'RIFF')
                || substr($cabecera, 8, 4) !== 'WAVE') {
                return null;
            }

            $canales = 0;
            $frecuencia = 0;
            $bits = 0;
            $bytesPorSegundo = 0;
            $bytesDeDatos = 0;
            $formato = 0;

            // Se recorren los bloques hasta encontrar "fmt " y "data"; un WAV
            // puede traer bloques intermedios (LIST, fact...) que hay que saltar.
            while (!feof($handle)) {
                $encabezadoBloque = (string) fread($handle, 8);
                if (strlen($encabezadoBloque) < 8) {
                    break;
                }

                $identificador = substr($encabezadoBloque, 0, 4);
                $tamano = unpack('V', substr($encabezadoBloque, 4, 4))[1] ?? 0;

                if ($identificador === 'fmt ') {
                    $fmt = (string) fread($handle, min($tamano, 40));
                    if (strlen($fmt) >= 16) {
                        $campos = unpack('vformato/vcanales/Vfrecuencia/VbytesPorSegundo/valineacion/vbits', $fmt);
                        $formato = (int) ($campos['formato'] ?? 0);
                        $canales = (int) ($campos['canales'] ?? 0);
                        $frecuencia = (int) ($campos['frecuencia'] ?? 0);
                        $bytesPorSegundo = (int) ($campos['bytesPorSegundo'] ?? 0);
                        $bits = (int) ($campos['bits'] ?? 0);
                    }

                    $restante = $tamano - strlen($fmt);
                    if ($restante > 0) {
                        fseek($handle, $restante, SEEK_CUR);
                    }
                } elseif ($identificador === 'data') {
                    $bytesDeDatos = $tamano;
                    break;
                } else {
                    fseek($handle, $tamano, SEEK_CUR);
                }

                // Los bloques se alinean a bytes pares.
                if ($tamano % 2 === 1) {
                    fseek($handle, 1, SEEK_CUR);
                }
            }
        } finally {
            fclose($handle);
        }

        if ($canales <= 0 || $frecuencia <= 0 || $bits <= 0) {
            return null;
        }

        if ($bytesPorSegundo <= 0) {
            $bytesPorSegundo = (int) ($frecuencia * $canales * ($bits / 8));
        }

        $duracion = $bytesPorSegundo > 0 ? round($bytesDeDatos / $bytesPorSegundo, 3) : 0.0;

        return new WavInfo(
            canales: $canales,
            frecuenciaMuestreo: $frecuencia,
            bitsPorMuestra: $bits,
            duracionSegundos: $duracion,
            bytesDeDatos: $bytesDeDatos,
            codigoFormato: $formato,
        );
    }
}
