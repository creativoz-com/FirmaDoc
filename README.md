# FacturaScripts
Software de código abierto de facturación y contabilidad para pequeñas y medianas empresas.
Software ERP de código abierto. Construido sobre PHP, utilizando componentes Symfony y Bootstrap 4.
Fácil y potente.

# FirmaDoc
Plugin para FacturaScripts que permite recoger la firma de documentos comerciales (presupuestos,
albaranes, facturas y pedidos) directamente desde la plataforma, enviando un enlace al cliente
por email o WhatsApp.

## Qué tipo de firma genera

FirmaDoc genera **firma electrónica simple** en el sentido del art. 3.10 del Reglamento (UE)
910/2014 (eIDAS): datos en formato electrónico que el firmante utiliza para firmar. Es
admisible como prueba —el art. 25.1 impide denegarle efectos jurídicos por ser electrónica—
y el plugin acompaña cada firma de un certificado PDF con las evidencias recogidas: nombre,
NIF, cargo, fecha y hora, dirección IP, navegador, aperturas del enlace y hash del documento
en el momento de la firma.

Opcionalmente puede reforzarse con dos cosas que se configuran en **Administrador →
Firma Documentos**:

- **Verificación en dos pasos**: antes de firmar se envía un código de un solo uso a la
  dirección que la empresa registró para el firmante. Acredita que quien firma tiene acceso
  a ese buzón, y queda reflejado en el certificado.
- **Sello de tiempo (RFC 3161)**: la fecha de la firma la certifica una autoridad de sellado
  independiente, no el reloj del servidor. El token se guarda entero y se puede validar con
  herramientas estándar (`openssl ts -verify`).

  **Importante sobre el sello:** la autoridad que viene por defecto (`freetsa.org`) es
  gratuita pero **no es un prestador cualificado** de la lista europea de confianza. El sello
  acredita la fecha frente a un tercero, que ya es mucho más que el reloj propio, pero no
  produce un sello cualificado en el sentido del art. 42 de eIDAS. Si necesita esa condición,
  configure la URL de una TSA cualificada de su prestador.

**No es firma electrónica avanzada ni cualificada.** En concreto:

- La firma con certificado digital (AutoFirma) se emplea para **identificar al firmante** y
  tomar sus datos del certificado como evidencia. No produce una firma criptográfica PAdES
  embebida en el PDF ni un sello de tiempo cualificado.
- No interviene ningún prestador cualificado de servicios de confianza.

Si necesita firma avanzada o cualificada con valor probatorio reforzado, este plugin no la
proporciona todavía.

<strong>ESTE PLUGIN NO ES SOFTWARE LIBRE. NO SE PERMITE SU LIBRE DISTRIBUCIÓN.</strong>
Consulte el archivo LICENSE incluido en este paquete para conocer los términos completos de uso.

## Componentes de terceros

`Assets/autoscript.js` es el cliente **AutoScript v1.9.0** del proyecto @firma, publicado por
la Secretaría General de Administración Digital del Gobierno de España bajo licencia dual
**GPL v2 o posterior / EUPL v1.1**. Se distribuye sin modificaciones y sus términos de licencia
prevalecen sobre los de este plugin para ese fichero. Código fuente y licencia originales:
https://github.com/ctt-gob-es/clienteafirma

`Assets/Fonts/DancingScript-SemiBold-*.woff2` es la tipografía **Dancing Script**, de The
Dancing Script Project Authors, bajo **SIL Open Font License 1.1** (véase
`Assets/Fonts/OFL.txt`). Se emplea para la firma tipográfica y va empaquetada en el plugin
para no pedírsela a Google Fonts, que recibiría la dirección IP del firmante.

## Nombre de carpeta
Como con todos los plugins, la carpeta se debe llamar igual que el plugin. En este caso **FirmaDoc**.

## Requisitos
- FacturaScripts 2025 o superior
- PHP 8.1 o superior, con las extensiones `zip` y `dom` (vienen de serie)
- Para firma con certificado digital: AutoFirma instalado en el equipo del firmante

No hace falta instalar nada más. La conversión de Word a PDF usa LibreOffice si el servidor
lo tiene, y si no la resuelve el propio plugin.

## Características principales
- Firma manuscrita y tipográfica, con identificación opcional mediante certificado digital (AutoFirma/FNMT)
- Envío a firma de documentos propios: PDF y Word (.docx), que se convierte a PDF
  conservando texto, títulos, listas, tablas e imágenes, logotipo de cabecera incluido
- Etiqueta `{{firma.aqui}}` en la plantilla de Word para colocar el recuadro de firma
- Marca de firma en el margen de cada página del documento firmado
- Pensado para que otros plugins lo usen: véase [API.md](API.md)
- Multi-firmante: modo paralelo y secuencial
- Certificado PDF de evidencias con datos del firmante, IP, hash y fecha
- Portal público de verificación con código QR en el certificado
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
