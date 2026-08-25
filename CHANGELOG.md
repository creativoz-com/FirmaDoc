# Registro de cambios

## 1.5.1

- La conversión de Word **conserva las imágenes**, incluido el logotipo del membrete,
  que vive en un fichero aparte de Word y se perdía entero. Se respeta el tamaño y la
  alineación que les dio Word, y se reducen si no caben a lo ancho.

  Esto deshace la disyuntiva que dejaba la 1.5: un contrato con logotipo y con
  `{{firma.aqui}}` ya no obliga a elegir entre una cosa y la otra, ni a instalar
  LibreOffice en el servidor.

  Sigue sin reproducirse la maquetación fina —columnas, cuadros de texto, pie de
  página—, y el aviso que sale al convertir ya lo dice con precisión.

## 1.5

### Envío de documentos propios

- Se puede mandar a firmar un **Word (.docx)**, no solo un PDF. El documento se
  convierte y lo que se firma es el PDF: el firmante tiene que ver exactamente lo mismo
  que queda sellado, y un .docx se abre distinto en cada ordenador. No hace falta
  instalar nada: si el servidor tiene LibreOffice se usa, y si no, la conversión la
  resuelve el propio plugin con lo que ya trae PHP. Se conservan párrafos, alineación,
  negrita, cursiva, títulos, listas —numeradas y de viñeta— y tablas; no la maquetación
  exacta, y en ese caso se avisa de que conviene repasar el PDF.
- La etiqueta **`{{firma.aqui}}`** en la plantilla marca dónde va el recuadro de firma,
  y `{{firma.aqui:2}}` de qué firmante es. Antes de firmar se ve la línea con su rótulo;
  al firmar, la rúbrica queda dentro con el nombre, el documento de identidad y la fecha.
- **Varios documentos por solicitud**, separando los que se firman de los anexos que solo
  acompañan, porque la diferencia es jurídica.

### Para otros plugins

- **`FirmaDocApi`**: enviar a firmar, consultar el estado, recuperar el documento
  firmado, cancelar, reenviar y borrar, sin conocer el código de FirmaDoc. Documentado
  en [API.md](API.md).
- **`alFirmar()`** avisa cuando se completa una firma.
- Cada solicitud guarda **de dónde nace** (plugin, modelo e identificador), para que
  quien la creó la reencuentre sin guardarse nada por su cuenta.

### Gestión

- **Menú propio** con listado de envíos, filtros y ficha de cada solicitud.
- Pestaña **«Documentos firmados»** en la ficha de clientes y de proveedores, con el
  histórico completo, y envío a firma desde la propia ficha sin salir de ella.
- Desde la ficha se puede **descargar el documento firmado y volver a enviarlo**, y los
  botones aparecen solo cuando tienen sentido para el estado del registro.

### Documento firmado

- **Marca de firma en el margen de cada página**: quién firmó, cuándo, el código de
  verificación y el número de página. Una hoja suelta de un contrato de veinte ya dice
  que está firmado sin llegar al certificado del final.

### Presentación y privacidad

- Las pantallas se ven **como el resto del ERP**: se migraron de Bootstrap 4 a 5, que es
  lo que usa FacturaScripts 2026.
- La pantalla de firma **ya no pide nada a servidores ajenos**. Antes cargaba Bootstrap y
  Font Awesome de un CDN y la letra manuscrita de Google Fonts, que recibía la dirección
  IP de quien estaba a punto de firmar. Ahora todo sale de la propia instalación.
- La pestaña de firmas **está traducida**; antes estaba escrita solo en español.
- El envío a firma exige **token de formulario**: no puede dispararse desde fuera.

## 1.4

- Retirada de las afirmaciones sobre eIDAS que el plugin no podía sostener.
- Verificación en dos pasos con código de un solo uso.
- Sello de tiempo RFC 3161.
- Código de verificación propio y portal público para comprobarlo.
- Envío a firma de un PDF subido por el usuario.

## 1.2

- Corrección de errores, soporte multiidioma, QR en el certificado y mejoras en móvil.
