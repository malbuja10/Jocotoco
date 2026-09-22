<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;

/**
 * Extrae el audio de la peticion. Se aceptan dos formas de envio:
 *
 *  1. multipart/form-data con el campo "audio" (curl -F "audio=@grabacion.wav"),
 *  2. cuerpo binario crudo con Content-Type de audio (curl --data-binary @...).
 *
 * En el segundo caso el cuerpo se copia a un archivo temporal en bloques,
 * de modo que una grabacion de decenas de MB no se carga en memoria.
 */
final class PayloadReader
{
    private const CHUNK_BYTES = 262144;

    public function __construct(
        private readonly string $temporaryDirectory,
        private readonly int $maxBytes,
    ) {
    }

    public function read(Request $request, string $fieldName = 'audio'): ReceivedPayload
    {
        $declaredLength = $request->contentLength();
        if ($declaredLength !== null && $declaredLength > $this->maxBytes) {
            throw HttpException::payloadTooLarge(
                sprintf('El envio declara %d bytes y el limite es %d.', $declaredLength, $this->maxBytes),
                ['maximo_bytes' => $this->maxBytes],
            );
        }

        if (str_starts_with($request->contentType(), 'multipart/form-data')) {
            return $this->fromMultipart($request, $fieldName);
        }

        return $this->fromRawBody($request);
    }

    private function fromMultipart(Request $request, string $fieldName): ReceivedPayload
    {
        $file = $request->file($fieldName);

        if ($file === null) {
            $received = array_map(static fn ($item): string => $item->field, $request->files());

            throw HttpException::badRequest(
                sprintf('Falta el campo de archivo "%s" en el multipart/form-data.', $fieldName),
                ['campos_recibidos' => array_values($received)],
            );
        }

        if (!$file->isValid()) {
            $status = in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;

            throw new HttpException(
                $status,
                $file->errorMessage(),
                $status === 413 ? 'Carga demasiado grande' : 'Solicitud invalida',
                'https://jocotoco.api/problems/subida-fallida',
            );
        }

        return new ReceivedPayload(
            temporaryPath: $file->temporaryPath,
            clientFilename: $file->clientFilename,
            declaredMediaType: $file->clientMediaType,
            fields: $request->parsedBody(),
            temporaryOwned: true,
        );
    }

    private function fromRawBody(Request $request): ReceivedPayload
    {
        $body = $request->body();
        if (!is_resource($body)) {
            throw HttpException::badRequest('No se recibio ningun cuerpo con audio.');
        }

        $temporaryPath = $this->createTemporaryFile();
        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            throw HttpException::serverError('No se pudo crear el archivo temporal para el audio.');
        }

        $written = 0;
        try {
            while (!feof($body)) {
                $chunk = fread($body, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw HttpException::badRequest('La lectura del cuerpo de la peticion fallo.');
                }

                if ($chunk === '') {
                    continue;
                }

                $written += strlen($chunk);
                if ($written > $this->maxBytes) {
                    throw HttpException::payloadTooLarge(
                        sprintf('El audio supera el limite de %d bytes.', $this->maxBytes),
                        ['maximo_bytes' => $this->maxBytes],
                    );
                }

                if (fwrite($target, $chunk) === false) {
                    throw HttpException::serverError('No se pudo escribir el audio en el almacenamiento temporal.');
                }
            }
        } catch (HttpException $exception) {
            fclose($target);
            @unlink($temporaryPath);

            throw $exception;
        }

        fclose($target);

        if ($written === 0) {
            @unlink($temporaryPath);

            throw HttpException::badRequest('El cuerpo de la peticion esta vacio.');
        }

        return new ReceivedPayload(
            temporaryPath: $temporaryPath,
            clientFilename: $this->filenameFromHeaders($request),
            declaredMediaType: $request->contentType(),
            fields: $this->fieldsFromHeaders($request),
            temporaryOwned: true,
        );
    }

    private function createTemporaryFile(): string
    {
        if (!is_dir($this->temporaryDirectory) && !@mkdir($this->temporaryDirectory, 0o750, true) && !is_dir($this->temporaryDirectory)) {
            throw HttpException::serverError(sprintf('No se pudo crear el directorio temporal %s.', $this->temporaryDirectory));
        }

        $path = @tempnam($this->temporaryDirectory, 'jocotoco-');
        if ($path === false) {
            throw HttpException::serverError('No se pudo reservar un archivo temporal.');
        }

        return $path;
    }

    private function filenameFromHeaders(Request $request): ?string
    {
        $header = $request->header('x-nombre-archivo');
        if ($header !== '') {
            return basename($header);
        }

        $disposition = $request->header('content-disposition');
        if ($disposition !== '' && preg_match('/filename="?([^";]+)"?/i', $disposition, $matches) === 1) {
            return basename($matches[1]);
        }

        return null;
    }

    /**
     * Metadatos para envios binarios: cabecera X-Metadatos con un objeto JSON,
     * mas los parametros de la query string.
     *
     * @return array<string,mixed>
     */
    private function fieldsFromHeaders(Request $request): array
    {
        $fields = [];

        $raw = $request->header('x-metadatos');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw HttpException::badRequest('La cabecera X-Metadatos debe contener un objeto JSON valido.');
            }
            $fields = $decoded;
        }

        $grabadoEn = $request->header('x-grabado-en');
        if ($grabadoEn !== '') {
            $fields['grabado_en'] = $grabadoEn;
        }

        foreach (['grabado_en', 'sitio', 'estacion', 'nota', 'especie', 'duracion_segundos', 'frecuencia_muestreo', 'canales', 'ganancia_db', 'latitud', 'longitud'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
