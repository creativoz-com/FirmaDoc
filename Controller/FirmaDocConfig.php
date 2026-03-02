<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.0
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig as ConfigModel;

class FirmaDocConfig extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Firma Documentos';
        $data['icon'] = 'fas fa-signature';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $config = ConfigModel::getConfig();
        $mensajeGuardado = '';

        $action = $this->request->request->get('action', '');
        if ($action === 'save') {
            $req = $this->request->request;
            $config->modo_manuscrita  = (bool) $req->get('modo_manuscrita', false);
            $config->modo_tipografica = (bool) $req->get('modo_tipografica', false);
            $config->modo_certificado = (bool) $req->get('modo_certificado', false);
            $config->nif_modo         = $req->get('nif_modo', 'opcional');
            $config->cargo_modo       = $req->get('cargo_modo', 'opcional');
            $config->dias_validez     = (int) $req->get('dias_validez', 15);
            $config->recordatorios_activo = (bool) $req->get('recordatorios_activo', false);

            // Tipos de documento activos
            $config->doc_presupuesto = (bool) $req->get('doc_presupuesto', false);
            $config->doc_albaran     = (bool) $req->get('doc_albaran', false);
            $config->doc_factura     = (bool) $req->get('doc_factura', false);
            $config->doc_pedido      = (bool) $req->get('doc_pedido', false);
            $diasRaw = trim($req->get('recordatorio_dias', '7,3,1'));
            // Sanitizar: solo números y comas
            $diasRaw = preg_replace('/[^0-9,]/', '', $diasRaw);
            $config->recordatorio_dias = $diasRaw ?: '7,3,1';
            $config->descarga_pdf     = $req->get('descarga_pdf', 'siempre');
            $config->notif_empresa    = (bool) $req->get('notif_empresa', false);
            $config->email_adicional  = $req->get('email_adicional', null) ?: null;
            $config->mostrar_logo     = (bool) $req->get('mostrar_logo', false);
            $config->legal_url        = $req->get('legal_url', null) ?: null;
            $config->legal_texto      = $req->get('legal_texto', null) ?: null;
            $config->email_asunto     = $req->get('email_asunto', '');
            $config->email_cuerpo     = $req->get('email_cuerpo', '');
            $config->whatsapp_mensaje = $req->get('whatsapp_mensaje', '');
            $config->confirm_asunto   = $req->get('confirm_asunto', '') ?: null;
            $config->confirm_cuerpo   = $req->get('confirm_cuerpo', '') ?: null;
            $mensajeGuardado = $config->save() ? 'ok' : 'error';
        }

        $this->setTemplate('FirmaDocConfig');
        $this->config = $config;
        $this->mensajeGuardado = $mensajeGuardado;
    }

    public $config;
    public $mensajeGuardado = '';
}
