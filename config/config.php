<?php

declare(strict_types=1);

/**
 * Valores por defecto de la aplicacion.
 *
 * Cada clave puede sobreescribirse con una variable de entorno con prefijo
 * JOCOTOCO_ y el nombre en mayusculas. Por ejemplo:
 *
 *   JOCOTOCO_MAX_UPLOAD_BYTES=134217728
 *   JOCOTOCO_ALLOWED_DEVICES="rpi-yanacocha-01,rpi-mindo-02"
 *   JOCOTOCO_CA_ISSUER_COMMON_NAME="Jocotoco Intermediate CA"
 *
 * En produccion las variables se definen en el pool de php-fpm
 * (deploy/php/jocotoco-pool.conf) o en /etc/jocotoco/api.env.
 */

$dataDir = '/var/lib/jocotoco';

return [
    // Identidad del servicio.
    'app_version' => '1.0.0',
    'app_name' => 'Jocotoco Audio API',

    // --- Seguridad mTLS -------------------------------------------------
    // require_mtls=false solo para desarrollo local sin certificados.
    'require_mtls' => true,
    // CN de la CA intermedia de step-ca que firma los certificados de
    // dispositivo. Use "*" para aceptar cualquier emisor que nginx valide.
    'ca_issuer_common_name' => '*',
    // Lista blanca de dispositivos (SAN DNS o CN). Vacio = cualquier
    // certificado valido emitido por la CA. Acepta comodines: "*.sensores.local".
    'allowed_devices' => [],
    // Clientes con permiso de consulta global (por ejemplo un panel interno).
    'reader_devices' => [],
    // Tolerancia de reloj al validar notBefore del certificado de cliente.
    'certificate_clock_skew_seconds' => 60,

    // --- Almacenamiento -------------------------------------------------
    'data_dir' => $dataDir,
    // Vacio => <data_dir>/audio, <data_dir>/jocotoco.sqlite, <data_dir>/tmp
    'audio_dir' => '',
    'db_path' => '',
    'tmp_dir' => '',
    'allow_delete' => false,

    // --- Limites de audio -----------------------------------------------
    // 64 MiB: ~ 6 min de WAV 48 kHz 16 bit mono.
    'max_upload_bytes' => 67108864,
    'min_upload_bytes' => 1024,
    'allowed_formats' => ['wav', 'flac', 'ogg', 'opus', 'mp3'],
    'recorded_at_future_tolerance_seconds' => 300,

    // --- Observabilidad -------------------------------------------------
    // Ruta del log JSON, o "stderr" para delegar a php-fpm/journald.
    'log_path' => '',
];
