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

use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocDocumento;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocEmail;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPDFExport;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPdfUnir;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocReenvio;
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

        // Una solicitud de firma no se crea a mano desde aquí: nace al subir un PDF o
        // al generarla desde un documento de venta, que es lo que le da su token,
        // su huella y sus firmantes.
        $this->views[$this->getMainViewName()]->setSettings('btnNew', false);

        $this->addListView('ListFirmaDocFirmante', 'FirmaDocFirmante', 'firmadoc-signers', 'fas fa-users')
            ->addOrderBy(['orden'], 'order', 1)
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);

        $this->addListView('ListFirmaDocReenvio', 'FirmaDocReenvio', 'firmadoc-resend-history', 'fas fa-paper-plane')
            ->addOrderBy(['fecha'], 'date', 2)
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
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

            case 'firmadoc-descargar':
                $this->descargarAction();
                return false;

            case 'firmadoc-enviar-firmado':
                $this->enviarFirmadoAction();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Carga la solicitud sobre la que se actúa.
     *
     * No vale getModel(): execPreviousAction() se ejecuta ANTES de loadData(), así que
     * en ese punto el modelo de la vista todavía está vacío y todas las acciones
     * creían estar ante un registro sin firmar.
     */
    private function firmaDeLaPeticion(): ?FirmaDoc
    {
        $codigo = $this->request->get('code', '');
        if (empty($codigo)) {
            return null;
        }

        $firma = new FirmaDoc();
        return $firma->loadFromCode($codigo) ? $firma : null;
    }

    /**
     * Sirve el documento firmado con su certificado, sin pasar por el enlace público:
     * desde dentro del ERP no hace falta el token.
     */
    private function descargarAction(): void
    {
        $firma = $this->firmaDeLaPeticion();
        if (null === $firma) {
            return;
        }

        $pdf = $this->generarPdfFirmado($firma);
        if ($pdf === '') {
            return;
        }

        $nombre = $this->nombreFicheroFirmado($firma);

        $this->setTemplate(false);
        $this->response->headers->set('Content-Type', 'application/pdf');
        $this->response->headers->set('Content-Disposition', 'attachment; filename="' . $nombre . '"');
        $this->response->setContent($pdf);
    }

    /**
     * Manda el documento ya firmado, con el certificado, a quien lo firmó.
     */
    private function enviarFirmadoAction(): void
    {
        $firma = $this->firmaDeLaPeticion();
        if (null === $firma) {
            return;
        }

        $destino = trim((string) ($firma->email_cliente ?? ''));
        if (empty($destino)) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-no-recipient'));
            return;
        }

        $pdf = $this->generarPdfFirmado($firma);
        if ($pdf === '') {
            return;
        }

        $mail = new NewMail();
        if (!$mail->canSendMail()) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-no-email-setup'));
            return;
        }

        // El adjunto se escribe en el directorio temporal de correo del núcleo,
        // que es de donde PHPMailer los toma, y se borra después del envío.
        $carpeta = FS_FOLDER . '/' . NewMail::ATTACHMENTS_TMP_PATH;
        Tools::folderCheckOrCreate($carpeta);
        $nombre = $this->nombreFicheroFirmado($firma);
        $ruta = $carpeta . uniqid('firmadoc_') . '_' . $nombre;

        if (file_put_contents($ruta, $pdf) === false) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-upload-store-failed'));
            return;
        }

        try {
            $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
            $mail->addAddress($destino);
            $mail->title = Tools::lang()->trans('firmadoc-signed-email-subject', [
                '%doc%' => $firma->getTitulo(),
            ]);
            $mail->addAttachment($ruta, $nombre);

            FirmaDocEmail::montar(
                $mail,
                Tools::lang()->trans('firmadoc-signed-email-body', ['%doc%' => $firma->getTitulo()]),
                FirmaDocUrl::verificacion($firma->codigo_verificacion ?? ''),
                Tools::lang()->trans('firmadoc-verify-header'),
                $documento
            );

            $mail->send();

            FirmaDocReenvio::registrar(
                $firma->id,
                $destino,
                'documento-firmado',
                FirmaDocReenvio::CANAL_EMAIL,
                null,
                $firma->firma_nombre,
                $this->user->nick ?? null
            );

            Tools::log()->notice(Tools::lang()->trans('firmadoc-send-email-sent', ['%email%' => $destino]));

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-resending-email', ['%error%' => $e->getMessage()]));
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * PDF del documento con su certificado de firma. Cadena vacía si no procede.
     */
    private function generarPdfFirmado(FirmaDoc $firma): string
    {
        if (empty($firma->id) || $firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-only-when-signed'));
            return '';
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-document-not-found'));
            return '';
        }

        // Documento subido: el original no se toca, se le añade el certificado detrás
        // si el servidor puede unir PDF; si no, se entrega el certificado aparte.
        if ($firma->esExterno()) {
            $ruta = FirmaDocDocumento::rutaFicheroExterno($firma);
            if ($ruta === '') {
                Tools::log()->warning(Tools::lang()->trans('firmadoc-document-not-found'));
                return '';
            }
            $certificado = FirmaDocPDFExport::certificadoSuelto($firma, $documento);
            return FirmaDocPdfUnir::unir($ruta, $certificado) ?? (string) file_get_contents($ruta);
        }

        $export = new FirmaDocPDFExport();
        $export->newDoc($documento->codigo ?? '', 0, '');
        $export->addBusinessDocPage($documento);
        $export->addCertificadoFirma($firma, $documento);
        return $export->getDoc();
    }

    private function nombreFicheroFirmado(FirmaDoc $firma): string
    {
        $base = $firma->esExterno()
            ? ($firma->getFichero()->filename ?? 'documento.pdf')
            : ($firma->codigo_doc ?: 'documento') . '.pdf';

        // Transliterar antes de limpiar: si no, cada acento se convierte en dos
        // guiones bajos y sale «declaracio__n_responsable.pdf».
        $limpio = @iconv('UTF-8', 'ASCII//TRANSLIT', $base);
        $limpio = $limpio === false ? $base : $limpio;
        $limpio = preg_replace('/[^A-Za-z0-9._-]+/', '_', $limpio);

        return trim($limpio, '_') ?: 'documento.pdf';
    }

    private function reenviarAction(): void
    {
        $firma = $this->firmaDeLaPeticion();
        if (null === $firma) {
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
        $firma = $this->firmaDeLaPeticion();
        if (null === $firma || $firma->estado !== FirmaDoc::ESTADO_PENDIENTE) {
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
