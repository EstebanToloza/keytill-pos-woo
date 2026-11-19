# Lanzamiento: lo que falta y en qué orden

Estado al 18-nov-2026. Lo que está hecho no se repite acá; esto es la lista de lo
que **no puedo hacer yo** y lo que queda pendiente, con el motivo.

---

## 1. Cosas que requieren una cuenta tuya

### WordPress.org

| Qué | Estado |
|---|---|
| **Usuario de WordPress.org** | ✅ `estebantoloza`, confirmado (el perfil existe). Ya está en `readme.txt` → `Contributors:`. |
| **Slug `keytill`** | ✅ **Libre** (verificado 18-nov-2026 contra la API de plugins: *Plugin not found*). Se reclama al hacer el submit — quien lo pida primero se lo queda. |
| **6 capturas de pantalla** | ✅ Generadas, en `.wordpress-org/`. Al hacer el submit van al repo SVN de .org, en su carpeta `assets/` — **no** en el zip del plugin. |

### Freemius — ✅ HECHO Y VERIFICADO

Producto `45553`, slug `keytill`. Plan PRO `80241` publicado, trial de 14 días
sin tarjeta, tres escalones (1 / 5 / ilimitado) a 99 / 249 / 499 por año.
SDK vendorizado en `vendor/freemius`. Sitio de prueba registrado
(install `20918828`) y el trial probado de punta a punta:

- Con trial activo: **7/7 compuertas abiertas**.
- Al vencer: **7/7 cerradas**, devolver a crédito da `keytill_pro_required`,
  pero devolver en efectivo sigue funcionando.
- **Los códigos de gift card ya emitidos siguen canjeándose sin licencia**, con
  su saldo intacto. Es la regla que no se negocia y quedó comprobada contra una
  licencia real.

Detalle que importó: con el trial, `is_paying()` es **false** y `is_trial()` es
true. Si la condición fuera solo `is_paying()`, ningún usuario en prueba vería
las funciones PRO. Por eso es `is_paying() || is_trial()`.

Quedan dos cosas del panel, no del código:
- **Support del plan PRO**: hoy dice "Forum Support" y el FAQ genera
  *"extra support is currently not provided"* en una página donde alguien está
  por pagar 99 dólares. Hay que poner Email.
- **Refund Policy**: hoy es "no refunds". Conviene 14 días, que coincide con
  el trial y baja el riesgo percibido.

<details><summary>Referencia: los datos del panel</summary>

Hay que dar de alta el producto en su panel y anotar tres datos:

- `id` del plugin
- `public_key`
- el slug (ya está puesto como `keytill`)

Además hay que definir **los planes y el precio**, que es decisión tuya y no
técnica.

Con eso, la integración ya está escrita y solo hay que:

1. Definir las credenciales en `wp-config.php`:

   ```php
   define( 'KEYTILL_FS_ID', '12345' );
   define( 'KEYTILL_FS_PUBLIC_KEY', 'pk_xxxxxxxxxxxxxxxx' );
   ```

   Van por constante y no en el código a propósito: la *secret key* nunca debe
   viajar en un plugin distribuido, y así el repo no guarda credenciales.
   (También se puede por el filtro `keytill_license_config`.)

2. Vendorizar el SDK en `freemius/` — el punto de entrada es `freemius/start.php`.

Mientras falte cualquiera de las dos, `KEYTILL_License::active()` devuelve
`false`, `KEYTILL_Pro` cae a su default y **el plugin funciona igual**. Eso no es
por las dudas: el zip que se publica tiene que poder correr sin el SDK.

</details>

---

## 2. El cambio de una línea, antes de publicar

En `includes/class-keytill-pro.php`:

```php
const UNLICENSED_DEFAULT = true;   // ← hoy
const UNLICENSED_DEFAULT = false;  // ← antes de publicar
```

Hoy está en `true` para que el desarrollo y las suites funcionen sin montar el
proveedor. En `false`, un sitio sin licencia queda en FREE.

**Ya está probado en los dos estados**: con `false` las 7 compuertas cierran, la
venta sigue funcionando y la caja digital rechaza con `keytill_pro_required`.
La suite `tools/tests/e2e-free.php` cubre el modo FREE forzándolo por filtro.

---

## 3. Una decisión que hay que tomar: Freemius en la versión gratuita

El SDK de Freemius **hace llamadas externas**. Hoy el `readme.txt` afirma en el
FAQ que no hay ninguna, y eso es verdad y está verificado. Al meter el SDK deja
de serlo, así que hay que elegir:

### Opción A — FREE con el SDK (lo que recomiendo)

- El usuario ve la oferta de PRO **dentro de su panel**: es el embudo, y es la
  razón principal por la que se usa Freemius.
- Freemius pide **consentimiento explícito** antes de enviar nada. Si el usuario
  declina, no se envía.
- Hay que **corregir el FAQ** del readme: pasar de «no hay llamadas externas» a
  algo honesto, del tipo «el POS no envía datos a ningún lado; la única conexión
  externa es la de la licencia, que pide tu permiso antes de activarse y se
  puede rechazar».

### Opción B — FREE sin el SDK

- La afirmación de privacidad queda absoluta, que es un argumento de venta real.
- Pero no hay embudo en el panel, y convertir gratis → pago se vuelve mucho más
  difícil. Y hay que mantener dos canales de distribución y un chequeo de
  licencia propio para PRO.

En las dos, la arquitectura ya sirve: `KEYTILL_License` está aislada y
`KEYTILL_Pro` no sabe quién le contesta.

---

## 4. Orden sugerido

1. Decidir A o B (arriba).
2. Dar de alta el producto en Freemius y definir precios.
3. Ajustar el FAQ del readme si va la opción A.
4. Pasar `UNLICENSED_DEFAULT` a `false`.
5. Correr las dos suites por última vez (`e2e-pos` y `e2e-free`).
6. Armar el zip respetando `.distignore` y hacer el submit.
7. Mientras espera la revisión, construir PRO.

Los puntos 1 y 2 son los únicos que no puedo hacer yo. El resto, cuando digas.

El paso 7 aprovecha la única latencia del proceso: la cola de revisión de
WordPress.org no la controlás, todo lo demás sí.
