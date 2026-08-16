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
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocDocumento;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

class FirmaDocVerify extends Controller
{
    /** @var FirmaDoc|null */
    public $firma;

    /** @var FirmaDocFirmante[] */
    public $firmantes = [];

    /**
     * Datos de firmantes ya enmascarados, que es lo único que ve la plantilla pública.
     * Cada elemento: ['nombre' => string, 'nif' => string, 'fecha' => string]
     *
     * @var array[]
     */
    public $firmantesPublicos = [];

    /** @var bool True si el hash coincide y el documento está firmado */
    public $verificado = false;

    /** @var bool True si se proporcionó un hash válido */
    public $hashValido = false;

    /** @var string */
    public $mensaje = '';

    /** @var string */
    public $mensajeTipo = '';

    /** @var string Raíz de la instalación, para servir los estilos sin salir a internet */
    public $urlBase = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = Tools::lang()->trans('firmadoc-verify-title');
        $data['icon'] = 'fas fa-shield-alt';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response)
    {
        parent::publicCore($response);
        $this->procesarVerificacion();
        $this->urlBase = FirmaDocUrl::base();
        $this->setTemplate('FirmaDocVerify');
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->procesarVerificacion();
        $this->urlBase = FirmaDocUrl::base();
        $this->setTemplate('FirmaDocVerify');
    }

    private function procesarVerificacion(): void
    {
        // Se acepta el código de verificación —lo que va impreso en el certificado y
        // en el QR— y, por compatibilidad, la huella de las firmas anteriores a la v1.4.
        $codigo = trim($this->request->get('codigo', ''));
        $hash   = trim($this->request->get('hash', ''));

        if (empty($codigo) && empty($hash)) {
            $this->hashValido = false;
            $this->mensaje = Tools::lang()->trans('firmadoc-verify-no-hash');
            $this->mensajeTipo = 'warning';
            return;
        }

        $this->hashValido = true;

        if (!empty($codigo)) {
            // Se normaliza para que dé igual cómo lo teclee quien verifica
            $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
            $this->firma = FirmaDoc::getByCodigoVerificacion($codigo);
        } else {
            $model = new FirmaDoc();
            $lista = $model->all(
                [new DataBaseWhere('doc_hash', $hash)],
                ['fecha_envio' => 'DESC'],
                0,
                1
            );
            $this->firma = empty($lista) ? null : $lista[0];
        }

        if (null === $this->firma) {
            $this->mensaje = Tools::lang()->trans('firmadoc-verify-not-found');
            $this->mensajeTipo = 'danger';
            return;
        }

        // Cargar firmantes
        $this->firmantes = FirmaDocFirmante::porSolicitud($this->firma->id);
        $this->prepararFirmantesPublicos();

        // Determinar estado
        switch ($this->firma->estado) {
            case FirmaDoc::ESTADO_FIRMADO:
                // La pantalla afirma que el documento no ha cambiado desde la firma.
                // Eso hay que comprobarlo de verdad: se recalcula el hash del documento
                // tal como está AHORA y se compara con el que se guardó al firmar. Antes
                // se daba por bueno con solo mirar el estado, que depende de que el hook
                // de modificación se haya disparado.
                if (!$this->documentoIntacto()) {
                    $this->verificado = false;
                    $this->mensaje = Tools::lang()->trans('firmadoc-verify-modified');
                    $this->mensajeTipo = 'danger';
                    break;
                }
                $this->verificado = true;
                $this->mensaje = Tools::lang()->trans('firmadoc-verify-verified');
                $this->mensajeTipo = 'success';
                break;

            case FirmaDoc::ESTADO_PENDIENTE:
                $this->verificado = false;
                $this->mensaje = Tools::lang()->trans('firmadoc-verify-pending');
                $this->mensajeTipo = 'warning';
                break;

            case FirmaDoc::ESTADO_EXPIRADO:
                $this->verificado = false;
                $this->mensaje = Tools::lang()->trans('firmadoc-verify-expired');
                $this->mensajeTipo = 'warning';
                break;

            case FirmaDoc::ESTADO_CANCELADO:
                $this->verificado = false;
                $this->mensaje = Tools::lang()->trans('firmadoc-verify-cancelled');
                $this->mensajeTipo = 'danger';
                break;

            case FirmaDoc::ESTADO_ANULADO_MOD:
                $this->verificado = false;
                $this->mensaje = Tools::lang()->trans('firmadoc-verify-voided');
                $this->mensajeTipo = 'danger';
                break;

            default:
                $this->verificado = false;
                $this->mensaje = Tools::lang()->trans('firmadoc-verify-status');
                $this->mensajeTipo = 'info';
                break;
        }
    }

    /**
     * Recalcula el hash del documento vivo y lo compara con el guardado al firmar.
     * Si el documento ya no existe se considera no verificable.
     */
    private function documentoIntacto(): bool
    {
        $documento = FirmaDocDocumento::cargar($this->firma->tipo_doc, (int) $this->firma->id_doc);
        if (null === $documento) {
            return false;
        }

        return $this->firma->documentoSinModificar($documento) === true;
    }

    /**
     * Esta pantalla es pública: basta con conocer el hash —que va impreso en el pie
     * del certificado y dentro del QR— para llegar hasta aquí. Por eso no se publican
     * el nombre completo ni el NIF de nadie: lo justo para que quien tiene el documento
     * delante confirme que la firma se corresponde con quien él ya sabe que firmó.
     */
    private function prepararFirmantesPublicos(): void
    {
        $this->firmantesPublicos = [];

        $firmados = array_filter(
            $this->firmantes,
            fn($f) => $f->estado === FirmaDocFirmante::ESTADO_FIRMADO
        );

        foreach ($firmados as $f) {
            $this->firmantesPublicos[] = [
                'nombre' => self::enmascararNombre($f->firma_nombre ?: ($f->nombre ?? '')),
                'nif'    => self::enmascararNif($f->firma_nif ?? ''),
                'fecha'  => $f->fecha_firma ?? '',
            ];
        }

        // Modo firmante único: no hay filas en firmadoc_firmante
        if (empty($this->firmantesPublicos) && !empty($this->firma->firma_nombre)) {
            $this->firmantesPublicos[] = [
                'nombre' => self::enmascararNombre($this->firma->firma_nombre),
                'nif'    => self::enmascararNif($this->firma->firma_nif ?? ''),
                'fecha'  => $this->firma->fecha_firma ?? '',
            ];
        }
    }

    /**
     * «Francisco José Matías Olivares» → «Francisco J. M. O.»
     */
    public static function enmascararNombre(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($partes)) {
            return '—';
        }

        $salida = [array_shift($partes)];
        foreach ($partes as $parte) {
            $salida[] = mb_strtoupper(mb_substr($parte, 0, 1)) . '.';
        }
        return implode(' ', $salida);
    }

    /**
     * «12345678Z» → «••••••78Z». Suficiente para cotejar, inútil para recopilar.
     */
    public static function enmascararNif(string $nif): string
    {
        $nif = trim($nif);
        if ($nif === '') {
            return '';
        }
        if (mb_strlen($nif) <= 3) {
            return str_repeat('•', mb_strlen($nif));
        }
        return str_repeat('•', mb_strlen($nif) - 3) . mb_substr($nif, -3);
    }
}
