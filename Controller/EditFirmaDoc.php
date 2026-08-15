<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.4
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocDocumento;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;

/**
 * Ficha de una solicitud de firma: sus datos, los firmantes y el historial de envíos.
 */
class EditFirmaDoc extends EditController
{
    public function getModelClassName(): string
    {
        return 'FirmaDoc';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'firmadoc';
        $data['title'] = 'firmadoc-request';
        $data['icon'] = 'fas fa-file-signature';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews(): void
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        $this->addListView('ListFirmaDocFirmante', 'FirmaDocFirmante', 'firmadoc-signers', 'fas fa-users')
            ->addOrderBy(['orden'], 'order', 1)
            ->disableColumn('id-firmadoc');

        $this->addListView('ListFirmaDocReenvio', 'FirmaDocReenvio', 'firmadoc-resend-history', 'fas fa-paper-plane')
            ->addOrderBy(['fecha'], 'date', 2);
    }

    protected function loadData($viewName, $view): void
    {
        $id = $this->getViewModelValue($this->getMainViewName(), 'id');

        switch ($viewName) {
            case 'ListFirmaDocFirmante':
            case 'ListFirmaDocReenvio':
                if (empty($id)) {
                    break;
                }
                $view->loadData('', [new DataBaseWhere('id_firmadoc', $id)]);
                break;

            default:
                parent::loadData($viewName, $view);
                $this->prepararEnlaces();
                break;
        }
    }

    /**
     * Enlaces útiles en la ficha: el de firma, el del documento y el de verificación.
     */
    private function prepararEnlaces(): void
    {
        $firma = $this->getModel();
        if (empty($firma->id)) {
            return;
        }

        $this->enlaceFirma = FirmaDocUrl::firma($firma->token);
        $this->enlaceVerificacion = FirmaDocUrl::verificacion($firma->codigo_verificacion ?? '');
        $this->enlaceDocumento = $this->enlaceFirma . '&action=ver_pdf';
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'firmadoc-reenviar':
                $this->reenviarAction();
                return true;

            case 'firmadoc-cancelar':
                $this->cancelarAction();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function reenviarAction(): void
    {
        $firma = $this->getModel();
        if (empty($firma->id)) {
            return;
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-document-not-found'));
            return;
        }

        if (FirmaDocMailer::reenviarEmail($firma, $documento, null, $this->user->nick ?? null)) {
            Tools::log()->notice(Tools::lang()->trans('firmadoc-send-email-sent', [
                '%email%' => $firma->email_cliente ?? '',
            ]));
            return;
        }

        Tools::log()->error(Tools::lang()->trans('firmadoc-send-email-error'));
    }

    private function cancelarAction(): void
    {
        $firma = $this->getModel();
        if (empty($firma->id) || $firma->estado !== FirmaDoc::ESTADO_PENDIENTE) {
            return;
        }

        $firma->estado = FirmaDoc::ESTADO_CANCELADO;
        if ($firma->save()) {
            Tools::log()->notice(Tools::lang()->trans('firmadoc-link-cancelled-notice'));
        }
    }

    /** @var string */
    public $enlaceFirma = '';

    /** @var string */
    public $enlaceVerificacion = '';

    /** @var string */
    public $enlaceDocumento = '';
}
