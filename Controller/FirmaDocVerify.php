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
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

class FirmaDocVerify extends Controller
{
    /** @var FirmaDoc|null */
    public $firma;

    /** @var FirmaDocFirmante[] */
    public $firmantes = [];

    /** @var bool True si el hash coincide y el documento está firmado */
    public $verificado = false;

    /** @var bool True si se proporcionó un hash válido */
    public $hashValido = false;

    /** @var string */
    public $mensaje = '';

    /** @var string */
    public $mensajeTipo = '';

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
        $this->setTemplate('FirmaDocVerify');
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->procesarVerificacion();
        $this->setTemplate('FirmaDocVerify');
    }

    private function procesarVerificacion(): void
    {
        $hash = trim($this->request->get('hash', ''));

        if (empty($hash)) {
            $this->hashValido = false;
            $this->mensaje = Tools::lang()->trans('firmadoc-verify-no-hash');
            $this->mensajeTipo = 'warning';
            return;
        }

        $this->hashValido = true;

        // Buscar el documento por doc_hash
        $model = new FirmaDoc();
        $lista = $model->all(
            [new DataBaseWhere('doc_hash', $hash)],
            ['fecha_envio' => 'DESC'],
            0,
            1
        );

        if (empty($lista)) {
            $this->firma = null;
            $this->mensaje = Tools::lang()->trans('firmadoc-verify-not-found');
            $this->mensajeTipo = 'danger';
            return;
        }

        $this->firma = $lista[0];

        // Cargar firmantes
        $this->firmantes = FirmaDocFirmante::porSolicitud($this->firma->id);

        // Determinar estado
        switch ($this->firma->estado) {
            case FirmaDoc::ESTADO_FIRMADO:
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
}
