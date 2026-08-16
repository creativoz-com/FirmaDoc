<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.11
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class FirmaDoc extends ModelClass
{
    use ModelTrait;

    // Estados posibles del documento
    // Modos multi-firmante
    const MODO_UNICO      = 'unico';       // Un solo firmante (comportamiento actual)
    const MODO_PARALELO   = 'paralelo';    // Varios firmantes, cualquier orden
    const MODO_SECUENCIAL = 'secuencial';  // Varios firmantes, en orden definido

    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_FIRMADO   = 'firmado';
    const ESTADO_EXPIRADO  = 'expirado';
    const ESTADO_CANCELADO = 'cancelado';
    const ESTADO_ANULADO_MOD = 'anulado_mod'; // Anulado por modificación del documento

    // Tipos de documento soportados
    const TIPO_FACTURA      = 'factura';
    const TIPO_PRESUPUESTO  = 'presupuesto';
    const TIPO_ALBARAN      = 'albaran';
    const TIPO_PEDIDO       = 'pedido';

    /**
     * Documento subido por el usuario (contrato, anexo, autorización…) que no procede
     * de ningún documento de venta de FacturaScripts.
     */
    const TIPO_EXTERNO      = 'externo';

    /** @var int Identificador único */
    public $id;

    /** @var string Tipo de documento: factura, presupuesto, albaran, pedido */
    public $tipo_doc;

    /** @var int ID del documento en FacturaScripts */
    public $id_doc;

    /** @var string Código visible del documento (ej: F-2025-001) */
    public $codigo_doc;

    /** @var string Token único SHA256 para el link público */
    public $token;

    /** @var string Email del cliente destinatario */
    public $email_cliente;

    /** @var string Teléfono del cliente para WhatsApp */
    public $telefono_cliente;

    /** @var string Fecha y hora de envío del link */
    public $fecha_envio;

    /** @var string Fecha y hora de expiración del link */
    public $fecha_expiracion;

    /** @var string|null Fecha y hora en que el cliente firmó */
    public $fecha_firma;

    /** @var string|null Imagen de la firma en Base64 */
    public $firma_imagen;

    /** @var string|null Nombre escrito por el cliente al firmar */
    public $firma_nombre;

    /** @var string|null IP desde donde se realizó la firma */
    public $ip_cliente;

    /** @var string|null User agent del navegador del cliente */
    public $user_agent;

    /** @var string Estado actual: pendiente, firmado, expirado, cancelado */
    public $estado;

    /** @var string|null Hash del documento al generar el token (para detectar modificaciones) */
    public $doc_hash;

    /** @var bool Si ya se notificó a la empresa por email */
    public $email_empresa_notificado;

    /** @var int Número de recordatorios enviados al cliente */
    public $recordatorios_enviados;

    /** @var string|null Fecha del último recordatorio enviado (YYYY-MM-DD) */
    public $fecha_ultimo_recordatorio;

    /** @var string|null Fecha en que el cliente abrió el enlace por primera vez */
    public $fecha_primera_apertura;

    /** @var int Número de veces que el cliente ha abierto el enlace */
    public $veces_visto;

    /** @var string|null Motivo de rechazo si el cliente rechazó firmar */
    public $motivo_rechazo;

    /** @var string|null NIF/CIF del firmante */
    public $firma_nif;

    /** @var string|null Cargo del firmante */
    public $firma_cargo;

    /** @var string|null Modo de firma usado: manuscrita, tipografica, certificado */
    public $modo_firma;

    /** @var string|null Observaciones del firmante al firmar */
    public $observaciones_firmante;

    /** @var bool Si el firmante aceptó el aviso legal */
    public $acepto_legal;

    /** @var string|null Fecha en que se aceptó el aviso legal */
    public $fecha_acepto_legal;

    /** @var string|null Datos del certificado digital (JSON con clave pública y firma) */
    public $firma_certificado_data;

    /** @var string Modo multi-firmante: unico, paralelo, secuencial */
    public $modo_multifirma;

    /** @var string|null Título del documento externo, para identificarlo */
    public $titulo;

    /** @var string|null Cliente al que se envió, si lo hay */
    public $codcliente;

    /** @var string|null Proveedor al que se envió, si lo hay */
    public $codproveedor;

    /** @var string|null Usuario que creó la solicitud */
    public $nick;

    /** @var string|null Código público de verificación, propio y único */
    public $codigo_verificacion;

    /** @var string|null Código de un solo uso enviado al firmante */
    public $otp_codigo;

    /** @var string|null Caducidad del código de un solo uso */
    public $otp_expira;

    /** @var int Intentos fallidos de introducir el código */
    public $otp_intentos;

    /** @var bool Si el firmante ya superó la verificación en dos pasos */
    public $otp_verificado;

    /** @var string|null Dirección a la que se envió el código */
    public $otp_enviado_a;

    /** @var string|null Token del sello de tiempo (RFC 3161) en base64 */
    public $sello_tiempo;

    /** @var string|null Fecha certificada por la autoridad de sellado */
    public $sello_fecha;

    /** @var string|null Autoridad que emitió el sello */
    public $sello_autoridad;

    /**
     * Nombre de la tabla en la base de datos
     */
    public static function tableName(): string
    {
        return 'firmadoc';
    }

    /**
     * Clave primaria
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Valores por defecto al crear un nuevo registro
     */
    public function clear(): void
    {
        parent::clear();
        $this->estado                   = self::ESTADO_PENDIENTE;
        $this->email_empresa_notificado = false;
        $this->otp_intentos             = 0;
        $this->otp_verificado           = false;
        $this->fecha_envio              = date('d-m-Y H:i:s');
    }

    /**
     * Genera un token único SHA256 para el link público
     */
    public function generarToken(): string
    {
        $this->token = hash('sha256', uniqid('firmadoc_', true) . random_bytes(16));
        return $this->token;
    }

    /**
     * Comprueba si el link ha expirado
     */
    public function estaExpirado(): bool
    {
        if (empty($this->fecha_expiracion)) {
            return false;
        }
        $expiracion = \DateTime::createFromFormat('d-m-Y H:i:s', $this->fecha_expiracion);
        if (!$expiracion) {
            return false;
        }
        return time() >= $expiracion->getTimestamp();
    }

    /**
     * Comprueba si el documento ya está firmado
     */
    public function estaFirmado(): bool
    {
        return $this->estado === self::ESTADO_FIRMADO;
    }

    /**
     * Comprueba si el link es válido (no expirado, no firmado, no cancelado)
     */
    public function esValido(): bool
    {
        if ($this->estaFirmado()) {
            return false;
        }
        if ($this->estado === self::ESTADO_CANCELADO) {
            return false;
        }
        if ($this->estaExpirado()) {
            // Actualizamos el estado si ha expirado
            $this->estado = self::ESTADO_EXPIRADO;
            $this->save();
            return false;
        }
        return true;
    }

    /**
     * Registra la firma del cliente
     * Guarda imagen, nombre, IP, user agent y marca como firmado
     */
    public function registrarFirma(string $imagen, string $nombre, string $ip, string $userAgent): bool
    {
        $this->firma_imagen  = $imagen;
        $this->firma_nombre  = $nombre;
        $this->ip_cliente    = $ip;
        $this->user_agent    = $userAgent;
        $this->fecha_firma   = date('d-m-Y H:i:s');
        $this->estado        = self::ESTADO_FIRMADO;

        return $this->save();
    }

    /**
     * Convierte modelClassName a tipo FirmaDoc
     */
    public static function getTipoDesdeClase(string $className): ?string
    {
        $mapa = [
            'FacturaCliente'     => self::TIPO_FACTURA,
            'PresupuestoCliente' => self::TIPO_PRESUPUESTO,
            'AlbaranCliente'     => self::TIPO_ALBARAN,
            'PedidoCliente'      => self::TIPO_PEDIDO,
        ];
        return $mapa[$className] ?? null;
    }

    public static function getTipoDesdeControlador(string $controllerName): ?string
    {
        $mapa = [
            'EditFacturaCliente'     => self::TIPO_FACTURA,
            'EditPresupuestoCliente' => self::TIPO_PRESUPUESTO,
            'EditAlbaranCliente'     => self::TIPO_ALBARAN,
            'EditPedidoCliente'      => self::TIPO_PEDIDO,
        ];
        return $mapa[$controllerName] ?? null;
    }

    /**
     * Busca firma activa (pendiente o firmada) para un documento
     */
    public static function getActivaPorDocumento(string $tipo, int $idDoc): ?self
    {
        $model = new self();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('tipo_doc', $tipo),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('id_doc', $idDoc),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_CANCELADO, '!='),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_ANULADO_MOD, '!='),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_EXPIRADO, '!='),
        ];
        $lista = $model->all($where, ['fecha_envio' => 'DESC'], 0, 1);
        return empty($lista) ? null : $lista[0];
    }

    /**
     * Calcula la huella del documento, para detectar modificaciones posteriores.
     *
     * Desde la v1.4 es SHA-256. Las firmas anteriores llevan MD5 y se siguen pudiendo
     * verificar: se distinguen por la longitud del hash guardado (32 frente a 64), así
     * que no hace falta migrar nada ni invalidar lo ya firmado.
     *
     * @param string $algoritmo Solo para recomprobar firmas antiguas; en firmas nuevas
     *                          se deja el valor por defecto.
     */
    public static function calcularHashDoc(object $documento, string $algoritmo = 'sha256'): string
    {
        // Campos de cabecera. Se incluyen empresa, serie y fecha para que dos documentos
        // de contenido idéntico —mismo importe, mismo cliente— no den la misma huella.
        $datos = [
            (string)($documento->idempresa ?? ''),
            (string)($documento->codserie ?? ''),
            (string)($documento->codigo ?? ''),
            (string)($documento->fecha ?? ''),
            round((float)($documento->total ?? 0), 4),
            round((float)($documento->neto ?? 0), 4),
            round((float)($documento->totaliva ?? 0), 4),
            round((float)($documento->totalirpf ?? 0), 4),
            round((float)($documento->totalrecargo ?? 0), 4),
            (string)($documento->nombrecliente ?? ''),
            (string)($documento->cifnif ?? ''),
            (string)($documento->direccion ?? ''),
            (string)($documento->observaciones ?? ''),
        ];
        // Incluir líneas: descripción, cantidad, precio y descuento
        if (method_exists($documento, 'getLines')) {
            foreach ($documento->getLines() as $linea) {
                $datos[] = (string)($linea->descripcion ?? '');
                $datos[] = round((float)($linea->cantidad ?? 0), 4);
                $datos[] = round((float)($linea->pvpunitario ?? 0), 4);
                $datos[] = round((float)($linea->dtopor ?? 0), 4);
            }
        }
        return hash($algoritmo, json_encode($datos));
    }

    /**
     * Recalcula la huella del documento con el mismo algoritmo con el que se guardó
     * y la compara con la almacenada. Devuelve null si no se puede comprobar.
     */
    public function documentoSinModificar(object $documento): ?bool
    {
        if (empty($this->doc_hash)) {
            return null;
        }

        // Con paquete, la huella es la del conjunto de documentos firmables
        if ($this->tienePaquete()) {
            $hashes = [];
            foreach ($this->getAdjuntos(FirmaDocAdjunto::TIPO_FIRMAR) as $adjunto) {
                $ruta = $adjunto->getRuta();
                if ($ruta === '') {
                    return null;
                }
                $hashes[] = self::calcularHashFichero($ruta);
            }
            return hash_equals((string) $this->doc_hash, self::calcularHashConjunto($hashes));
        }

        // En documentos externos la huella es la del PDF subido
        if ($this->esExterno()) {
            $ruta = property_exists($documento, 'path') && !empty($documento->path)
                ? FS_FOLDER . '/' . $documento->path
                : '';
            if (empty($ruta) || !is_readable($ruta)) {
                return null;
            }
            return hash_equals((string) $this->doc_hash, self::calcularHashFichero($ruta));
        }

        // 32 caracteres = MD5 de una firma anterior a la v1.4; 64 = SHA-256
        $algoritmo = strlen($this->doc_hash) === 32 ? 'md5' : 'sha256';

        return hash_equals(
            (string) $this->doc_hash,
            self::calcularHashDoc($documento, $algoritmo)
        );
    }

    /**
     * Nombre del destinatario para listados: cliente, proveedor o la dirección suelta.
     */
    public function getDestinatario(): string
    {
        if (!empty($this->codcliente)) {
            $cliente = new \FacturaScripts\Core\Model\Cliente();
            if ($cliente->loadFromCode($this->codcliente)) {
                return $cliente->nombre;
            }
        }
        if (!empty($this->codproveedor)) {
            $proveedor = new \FacturaScripts\Core\Model\Proveedor();
            if ($proveedor->loadFromCode($this->codproveedor)) {
                return $proveedor->nombre;
            }
        }
        return (string) ($this->email_cliente ?? '');
    }

    /**
     * True si la firma es sobre un PDF subido, no sobre un documento de venta.
     */
    public function esExterno(): bool
    {
        return $this->tipo_doc === self::TIPO_EXTERNO;
    }

    /**
     * Fichero adjunto de un documento externo. En estas firmas `id_doc` guarda el
     * identificador del AttachedFile, que es donde FacturaScripts custodia el PDF.
     */
    public function getFichero(): ?\FacturaScripts\Core\Model\AttachedFile
    {
        if (!$this->esExterno() || empty($this->id_doc)) {
            return null;
        }
        return \FacturaScripts\Core\Model\AttachedFile::find($this->id_doc);
    }

    /**
     * El botón «+» de los listados llama a url('new'). Una solicitud de firma no se
     * crea rellenando una ficha en blanco —necesita ficheros, huella y firmantes—,
     * así que se lleva a la pantalla de subida.
     *
     * Ojo con el segundo parámetro: es el PREFIJO al que se concatena el nombre del
     * modelo ('List' + 'FirmaDoc'), no el nombre del controlador.
     */
    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'new') {
            return 'FirmaDocSubir';
        }

        return parent::url($type, $list);
    }

    /**
     * Adjuntos de la solicitud. Vacío en las firmas de un solo documento, que siguen
     * apuntando al fichero desde id_doc.
     *
     * @return FirmaDocAdjunto[]
     */
    public function getAdjuntos(string $tipo = ''): array
    {
        return empty($this->id) ? [] : FirmaDocAdjunto::porSolicitud((int) $this->id, $tipo);
    }

    /**
     * True si la solicitud lleva un paquete de documentos en lugar de uno solo.
     */
    public function tienePaquete(): bool
    {
        return !empty($this->getAdjuntos());
    }

    /**
     * Huella del conjunto de documentos que se firman.
     *
     * Se encadenan las huellas individuales en orden y se vuelve a resumir: así el
     * certificado puede acreditar tanto el paquete completo como cada pieza por
     * separado, y cambiar cualquiera de ellas —o su orden— rompe la huella global.
     *
     * @param string[] $hashes
     */
    public static function calcularHashConjunto(array $hashes): string
    {
        return hash('sha256', implode('|', $hashes));
    }

    /**
     * Huella de un PDF subido: SHA-256 del fichero entero.
     *
     * Para un documento externo la huella es más sólida que para uno de venta, porque
     * cubre el fichero byte a byte en lugar de un resumen de sus campos.
     */
    public static function calcularHashFichero(string $ruta): string
    {
        return is_readable($ruta) ? hash_file('sha256', $ruta) : '';
    }

    /**
     * Nombre con el que se presenta la firma en pantallas y correos.
     */
    public function getTitulo(): string
    {
        if (!empty($this->titulo)) {
            return $this->titulo;
        }
        return trim(ucfirst($this->tipo_doc ?? '') . ' ' . ($this->codigo_doc ?? ''));
    }

    /**
     * Genera el código público de verificación.
     *
     * Es un identificador propio y único, no la huella del documento: el portal
     * buscaba por `doc_hash` y dos documentos de contenido idéntico devolvían el
     * expediente equivocado. Además, así el código impreso en el certificado no
     * revela la huella del contenido.
     */
    public function generarCodigoVerificacion(): string
    {
        do {
            // Base32 sin caracteres ambiguos: se lee y se teclea sin equivocarse
            $alfabeto = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            $codigo = '';
            for ($i = 0; $i < 16; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
        } while (self::getByCodigoVerificacion($codigo) !== null);

        $this->codigo_verificacion = $codigo;
        return $codigo;
    }

    public static function getByCodigoVerificacion(string $codigo): ?self
    {
        if (empty($codigo)) {
            return null;
        }
        $model = new self();
        $lista = $model->all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('codigo_verificacion', $codigo)],
            [],
            0,
            1
        );
        return empty($lista) ? null : $lista[0];
    }

    /**
     * Anula la firma por modificación del documento
     */
    public function anularPorModificacion(): bool
    {
        $this->estado = self::ESTADO_ANULADO_MOD;
        return $this->save();
    }

    /**
     * Busca un registro por su token
     */
    public static function getByToken(string $token): ?self
    {
        $model = new self();
        $lista = $model->all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('token', $token)],
            [],
            0,
            1
        );
        return empty($lista) ? null : $lista[0];
    }

    /**
     * Devuelve todos los documentos pendientes de firma
     */
    public static function getPendientes(): array
    {
        $model = new self();
        return $model->all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_PENDIENTE)],
            ['fecha_envio' => 'DESC']
        );
    }

    /**
     * Validaciones antes de guardar
     */
    public function test(): bool
    {
        if (empty($this->tipo_doc)) {
            \FacturaScripts\Core\Tools::log()->error(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-model-tipodoc-required'));
            return false;
        }
        if (empty($this->id_doc)) {
            \FacturaScripts\Core\Tools::log()->error(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-model-iddoc-required'));
            return false;
        }
        if (empty($this->token)) {
            $this->generarToken();
        }
        if (empty($this->codigo_verificacion)) {
            $this->generarCodigoVerificacion();
        }
        return parent::test();
    }
}
