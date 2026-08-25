# Usar FirmaDoc desde otro plugin

Todo lo que hace falta está en `FirmaDocApi`. Es lo único que se mantiene estable
entre versiones: los modelos, las tablas y los controladores pueden cambiar.

```php
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocApi;
```

Declara la dependencia en tu `facturascripts.ini`:

```ini
require = FirmaDoc
```

## Mandar algo a firmar

```php
$firma = FirmaDocApi::enviar([
    'ficheros'   => ['/ruta/contrato.docx'],          // PDF o Word; obligatorio
    'firmantes'  => [                                  // obligatorio
        ['nombre' => 'Ana Pérez', 'email' => 'ana@ejemplo.com', 'telefono' => '600111222'],
    ],
    'titulo'     => 'Contrato de obra 2026/014',
    'modo'       => 'paralelo',                        // unico | paralelo | secuencial
    'codcliente' => '42',                              // para que salga en su ficha
    'origen_plugin' => 'MiPlugin',                     // para reencontrarla luego
    'origen_modelo' => 'ContratoObra',
    'origen_id'     => 14,
]);

if (null === $firma) {
    // el motivo es una clave de traducción ya existente
    Tools::log()->warning(Tools::lang()->trans(FirmaDocApi::getError()));
}
```

Un Word se convierte a PDF y lo que se firma es el PDF. Los ficheros se copian, así
que el original se queda donde estaba; con `'mover' => true` se mueven.

`'enviar_emails' => false` crea la solicitud sin avisar a nadie, por si prefieres
pasar tú el enlace. A quién se avisó: `FirmaDocApi::getEnviadoA()`.

## Dónde firma el firmante

Si la plantilla de Word lleva `{{firma.aqui}}`, ahí se pinta el recuadro de firma, y
al firmar se estampa la rúbrica dentro con el nombre, el documento de identidad y la
fecha. Con varios firmantes, `{{firma.aqui:2}}` es el segundo, `{{firma.aqui:3}}` el
tercero. Sin etiqueta, la firma va en el certificado del final, como siempre.

La etiqueta puede estar partida por Word en varios trozos; da igual.

## Enterarse de que han firmado

Desde el `init()` de tu plugin, porque la firma ocurre en la petición del firmante:

```php
public function init(): void
{
    FirmaDocApi::alFirmar(function (array $firma) {
        if ($firma['origen_plugin'] !== 'MiPlugin') {
            return;
        }
        // $firma['origen_id'] es tu registro
        // $firma['id'] es la solicitud; pide el PDF cuando lo necesites
    });
}
```

Al oyente le llega un array, nunca el modelo. Si revienta, se registra en el log y la
firma sigue su curso: el documento ya está firmado y el firmante no tiene por qué ver
un error de otro plugin.

## Consultar y recuperar

```php
FirmaDocApi::estado($id);                  // estado, firmantes, enlaces, fechas
FirmaDocApi::pdfFirmado($id);              // el firmado con su certificado, o null
FirmaDocApi::pdfOriginal($id);             // tal como se envió, sin certificado
FirmaDocApi::documentoIntacto($id);        // si sigue siendo el que se firmó

FirmaDocApi::porOrigen('MiPlugin', 'ContratoObra', '14');
FirmaDocApi::ultimaPorOrigen('MiPlugin', 'ContratoObra', '14');
FirmaDocApi::porTercero('42');             // por cliente; o ('', 'PROV1') por proveedor
```

`estado()` devuelve `null` si la solicitud no existe, y `pdfFirmado()` devuelve `null`
mientras no esté firmada. `documentoIntacto()` devuelve `false` también cuando no hay
nada que comprobar: ante la duda, no se afirma que esté intacto.

## Gobernar la solicitud

```php
FirmaDocApi::cancelar($id);       // el enlace deja de servir; no borra nada
FirmaDocApi::reenviar($id);       // vuelve a mandar el enlace a quien falte
FirmaDocApi::eliminar($id);       // solo si no está firmada
```

Una firma completada no se borra: es la prueba de que alguien firmó, y esa prueba no
puede evaporarse porque se borre un contrato. `eliminar($id, true)` fuerza el borrado,
y deberías tener una razón muy buena.

## Antes de ofrecer el botón

```php
$estado = FirmaDocApi::disponible();
if (false === $estado['listo']) {
    // $estado['motivos'] son claves de traducción: sin correo configurado,
    // sin dirección pública del sitio…
}
```

## Convertir un Word sin enviarlo

Para enseñar el documento antes de mandarlo:

```php
$error = '';
$pdf = FirmaDocApi::wordAPdf('/ruta/contrato.docx', $error);
```

Si el servidor tiene LibreOffice se usa; si no, la conversión la hace FirmaDoc y
conserva texto, negrita, títulos, listas y tablas, pero no la maquetación exacta ni
las imágenes. Con `{{firma.aqui}}` se usa siempre la conversión propia, porque hay que
saber dónde quedó el recuadro.
