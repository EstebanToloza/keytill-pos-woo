# Lanzamiento: lo que falta y en qué orden

Estado al 9-nov-2026. Lo que está hecho no se repite acá; esto es la lista de lo
que **no puedo hacer yo** y lo que queda pendiente, con el motivo.

---

## 1. Cosas que requieren una cuenta tuya

### WordPress.org

| Qué | Dónde aparece | Por qué no lo puedo hacer |
|---|---|---|
| **Usuario de WordPress.org** | `readme.txt` → `Contributors:` | Hoy dice `estebantoloza`, que me inventé. Tiene que ser tu usuario real o el submit no te identifica como autor. |
| **Verificar el slug `keytill`** | La URL será `wordpress.org/plugins/keytill` | Se reclama al hacer el submit. Si está tomado, hay que elegir otro y renombrar el text-domain. |
| **6 capturas de pantalla** | La sección `Screenshots` ya las describe | Van como `assets/screenshot-1.png` … `-6.png` **en el repo SVN de .org**, no en el zip del plugin. |

### Freemius

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
3. Generar las 6 capturas.
4. Poner el usuario real de WordPress.org en `Contributors`.
5. Ajustar el FAQ del readme si va la opción A.
6. Pasar `UNLICENSED_DEFAULT` a `false`.
7. Correr las dos suites por última vez (`e2e-pos` y `e2e-free`).
8. Armar el zip respetando `.distignore` y hacer el submit.
9. Mientras espera la revisión, construir PRO.

El paso 9 aprovecha la única latencia del proceso: la cola de revisión de
WordPress.org no la controlás, todo lo demás sí.
