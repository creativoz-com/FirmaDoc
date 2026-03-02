# FacturaScripts
Software de código abierto de facturación y contabilidad para pequeñas y medianas empresas.
Software ERP de código abierto. Construido sobre PHP, utilizando componentes Symfony y Bootstrap 4.
Fácil y potente.

# FirmaDoc
Plugin para FacturaScripts que permite la firma digital de documentos comerciales (presupuestos,
albaranes, facturas y pedidos) directamente desde la plataforma, con soporte para firma manuscrita,
tipográfica y mediante certificado digital (AutoFirma/FNMT). Compatible con el Reglamento eIDAS
(UE 910/2014).

<strong>ESTE PLUGIN NO ES SOFTWARE LIBRE. NO SE PERMITE SU LIBRE DISTRIBUCIÓN.</strong>
Consulte el archivo LICENSE incluido en este paquete para conocer los términos completos de uso.

## Nombre de carpeta
Como con todos los plugins, la carpeta se debe llamar igual que el plugin. En este caso **FirmaDoc**.

## Requisitos
- FacturaScripts 2025 o superior
- PHP 8.1 o superior
- Para firma con certificado digital: AutoFirma instalado en el equipo del firmante

## Características principales
- Firma manuscrita, tipográfica y con certificado digital (AutoFirma/FNMT)
- Multi-firmante: modo paralelo y secuencial
- Certificado PDF con datos del firmante, IP, hash y fecha (válido eIDAS)
- Recordatorios automáticos por email antes de la expiración del enlace
- Historial completo de envíos y auditoría de aperturas
- Plantillas de email configurables con variables dinámicas
- Compatible con WhatsApp para el envío del enlace de firma

## Instalación
1. Descargue el plugin y descomprímalo en la carpeta `Plugins/FirmaDoc/` de su instalación de FacturaScripts.
2. Acceda al panel de administración y pulse **Reconstruir**.
3. Active el plugin desde el menú de plugins.
4. Configure las opciones desde **Administrador → Firma Documentos**.

## Más información
- Información general: https://www.facturascripts.com
- Web del autor: https://creativoz.com

## Documentación / Soporte
Para soporte técnico, contacte con el autor en fmatias@creativoz.com

## Autor
Francisco José Matías Olivares
[Creativoz](https://creativoz.com) — fmatias@creativoz.com

## Otros plugins del mismo autor
- Próximamente en https://facturascripts.com

## Enlaces de interés
- [Cómo instalar plugins en FacturaScripts](https://facturascripts.com/publicaciones/como-instalar-un-plugin-en-facturascripts)
- [Programa para hacer facturas gratis](https://facturascripts.com/programa-para-hacer-facturas)
- [Cómo instalar FacturaScripts en Windows](https://facturascripts.com/instalar-windows)
- [AutoFirma — Firma electrónica del Gobierno de España](https://firmaelectronica.gob.es/Home/Descargas.html)
