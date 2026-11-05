# Keytill POS (`keytill`)

Punto de venta de mostrador para **Keytill**, como plugin de WordPress sobre el **mismo WooCommerce** de la tienda. Pantalla completa, operado por teclado, para el rol **cajero**. Las ventas son órdenes de Woo reales: stock, catálogo y clientes quedan **unificados con la web**.

- **Stack:** PHP + WooCommerce · **Alpine.js** (vendorizado local, sin build) · CSS plano + `tokens.css`. Sin frameworks JS, sin bundler, sin SQL directo (todo con las clases CRUD de Woo).
- **Compatibilidad:** WooCommerce **HPOS** (custom order tables) declarada.
- **Soporte táctil:** además del desktop por teclado, la pantalla de **Venta** funciona en **móvil y tablet** (táctil) — ver [Móvil / Tablet](#móvil--tablet-táctil).
- **Versión:** 0.8.9

---

## Requisitos

| | Mínimo |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WooCommerce | 8.0+ (probado hasta 11) · HPOS activo |
| Navegador (POS) | **Chrome** (pestaña normal) |

> En Firefox la tecla `F7` (devolución) dispara *caret browsing*; el POS asume Chrome.

---

## Instalación

Este repositorio **es el plugin**: se clona (o se copia) dentro de `wp-content/plugins/` de una instalación WordPress + WooCommerce.

```bash
cd wp-content/plugins
git clone git@github.com:EstebanToloza/POS-woo.git keytill
```

Después, en el sitio:

1. **Activar** el plugin *Keytill POS*. Al activarse crea el rol **`cajero`** (capability `keytill_operate`) y el rol **`encargado`** (`keytill_operate` + `keytill_manage` + `upload_files`); el administrador recibe ambas capabilities.
2. Crear una página **"Caja"** con el shortcode `[keytill]` y asignarle el template **"Keytill — Pantalla completa"**.
3. Crear un usuario con rol **cajero** (o encargado) e ingresar a esa página.
4. *(Opcional, demo)* Cargar catálogo de prueba: `wp keytill seed-demo` (idempotente; `--force` reescribe stock/precios).

Un usuario sin `keytill_operate` ve un aviso de acceso; el cajero ve el POS a pantalla completa.

> No hay paso de build: Alpine está vendorizado en `assets/js/alpine.min.js` y el CSS es plano. Editás y recargás.

---

## Roles

- **Cajero** (`keytill_operate`): vende, hace devoluciones, opera la caja, gestiona clientes y **ve/busca** las pestañas de la consola.
- **Encargado** (`keytill_operate` + `keytill_manage` + `upload_files`): además **edita/crea productos** (precio, stock, activo, editor completo, variaciones, imágenes/galería) y acciones administrativas.
- **Administrador:** tiene ambas capabilities.

La migración de roles es idempotente (option `keytill_roles_version`), se aplica en `init` para instalaciones ya activas.

---

## La consola (pestañas)

El POS es una **consola con pestañas** — Venta · Pedidos · Productos · Clientes — que cambian **sin recargar** la página. La pantalla de **Venta** conserva el manejo por teclado.

- **Venta:** carrito por teclado/lector de código, cobro (efectivo/tarjeta/transferencia/QR con vuelto), cliente fiscal, descuento (**por porcentaje o monto fijo $**), arqueo de caja y devoluciones. Soporta productos simples y **variables** (cada variación es un ítem escaneable con SKU/precio/stock propios).
- **Pedidos:** lista paginada de pedidos (POS **y** web) con búsqueda (N° o cliente), presets de fecha y filtros (origen, medio, estado, cajero). Detalle con **Reimprimir** el comprobante y **Devolver** (reutiliza el flujo de devolución del mostrador).
- **Productos:** lista con búsqueda (nombre/SKU/código de barras) y filtros (categoría, estado, stock). Con permiso de **gestión**: **edición rápida** inline (precio/stock/activo/**código de barras**) + **editor completo** (drawer). Ver abajo. El cajero navega en **solo lectura**.
- **Código de barras:** campo de 12 dígitos por producto (simples) o por variación (variables), **escaneable en Venta** (agrega la variación exacta al carrito). Botón **Etiquetas** → genera una hoja **A4** con la cantidad indicada de etiquetas **3,5×3,5 cm** con código **Code 128**, nombre, color/talle y el número, lista para imprimir o guardar como PDF (sin dependencias: SVG propio + impresión del navegador).
- **Clientes:** lista con búsqueda (nombre/email/DNI); detalle con estadísticas (pedidos, total gastado), edición (nombre/email/DNI/condición fiscal) e historial de compras (reimprimible). Alta con deduplicación por email y DNI. Son los mismos clientes de WooCommerce y **el POS no manda emails** al crear/editar.

### Editor de productos

Alta y edición de productos sin entrar al backend de Woo (requiere `keytill_manage`):

- **Simples:** nombre, SKU, categoría, precio + precio de oferta, stock, activo, descripción corta/larga e **imagen principal** (se sube al media library desde el POS).
- **Variables (talles/colores):** con **atributos globales `pa_` reutilizables**. El editor **levanta los atributos que ya existan en el sitio** y todo es **aditivo y no destructivo** (nunca borra ni renombra atributos/términos globales). Flujo: elegir/crear atributos + sus valores → **generar la grilla** de variaciones → editar SKU/precio/stock por variación (+ borrar variación).
- **Galería:** varias imágenes por producto, se suben desde el POS.
- **Fuera del editor (se gestiona en Woo):** imagen por variación, SEO, envíos, import masivo. Subir imágenes requiere `upload_files` (lo tiene el encargado, no el cajero).

---

## Uso (teclado)

`F2` cobrar · `F4` cliente · `F7` devolución · `F8` descuento · `F9` cerrar caja · `F10` retiro · `ESC` cancelar.

- El **campo de comando** funciona como lector de código de barras (SKU = código). Prefijo `N*` para N unidades.
- **Requiere abrir caja** (fondo inicial) antes de vender, para que el arqueo tenga sentido.
- Tras cobrar, el modal de confirmación permite **imprimir el comprobante** con `P` (o `ENTER`/`ESC` para continuar).

---

## Móvil / Tablet (táctil)

La pantalla de **Venta** se adapta a pantallas táctiles (móvil y tablet) **sin perder** la experiencia de desktop por teclado. El modo se detecta con `matchMedia('(pointer: coarse)')` (JS) + media queries `(pointer: coarse)`/`(orientation)` (CSS); en desktop (pointer fino) no cambia nada.

- **Sin auto-foco:** en táctil el POS **no** fuerza el foco al campo de comando (en desktop sí, contrato sagrado), así el teclado del sistema no salta solo — aparece únicamente cuando se toca un campo.
- **Barra de acciones** persistente (reemplaza las F-keys, targets ≥44px): fila con las acciones secundarias (Carrito · Cliente · Descuento · Devolución · Retiro · Cerrar caja) y **Cobrar** en una fila propia full-width debajo (acción primaria siempre visible).
- **Tablas de la consola** (Pedidos/Productos/Clientes): en pantalla chica scrollean en horizontal con la **primera columna fija** (N° de pedido / producto / cliente no se pierden de vista).
- **Agregar tocando** el resultado de la búsqueda; cantidad por línea con **steppers −/+/✕**.
- **Keypad numérico en pantalla** para el efectivo recibido y el descuento (no depende del teclado del sistema). Los modales pasan a **hojas** con botón grande de confirmar.
- **Layout:** teléfono y **tablet en vertical** → una columna con el **carrito como bottom sheet**; **tablet apaisada** → dos columnas tipo desktop adaptado; desktop (> 1024px) intacto.
- **Lector de código físico (HID/"keyboard wedge"):** soporte **latente**. Hay un campo de captura con `inputmode="none"` (recibe el escaneo sin abrir el teclado en pantalla), pero la experiencia táctil principal es tap + búsqueda visible; no hay escaneo por cámara.

---

## Configuración (menú «Keytill» → Ajustes)

En el admin, un único menú **«Keytill»** agrupa: **Inicio** (atajo a la pantalla del POS + estado), **Ajustes**, **Cajas** (historial de arqueos, solo lectura), **Créditos** y **Manual**. Cada submenú respeta su permiso (Ajustes/Cajas/Créditos = gerencia; Manual/Inicio = operador).

**Cajas** es el **historial de arqueos** (solo lectura, se genera desde el POS): lista de cada apertura→cierre con columnas apertura/cierre, cajero, estado, fondo, retiros, esperado, contado y **diferencia** (con color: faltante/sobrante/OK).

### Caja compartida (varios cajeros, un cajón)

El modelo es **una caja = un cajón físico**. Varios cajeros pueden estar logueados en sus propios dispositivos y **venden todos hacia la misma caja abierta** (cada venta registra quién la hizo). No es "una caja por persona" (eso requeriría cajones separados = multi-caja, fuera de alcance).

- El **cierre/arqueo** (F9) es una **función de control: solo el encargado o administrador** puede cerrarla (un cajero no cierra su propio cajón). El cajero ve el aviso y el botón/tecla no le abre el cierre.
- El arqueo del cierre incluye **ventas por cajero** (cuánto vendió cada uno), además del desglose por medio de pago.

**Ajustes** (rol gerencia) — todo tiene default sensato, no es obligatorio tocar nada:
- **Datos del negocio (comprobante):** nombre (default = nombre del sitio), CUIT/ID fiscal, dirección, teléfono y leyenda al pie del comprobante interno.
- **Medios de pago:** uno por línea, además de **Efectivo** (que es fijo: afecta cajón/vuelto/arqueo). El POS **solo registra** el medio para el arqueo — el cobro real se hace afuera (posnet, transferencia, app). Se pueden renombrar/agregar (ej. «Mercado Pago», «Tarjeta débito»), máx. 8. El id se deriva de la etiqueta, así los 4 por defecto (Tarjeta/Transferencia/QR) mantienen su id histórico.
- **Vigencia por defecto del crédito / gift cards** (días; 0 = sin vencimiento).
- **General:** etiqueta de la caja (ej. «CAJA 01»).

---

## Comprobante, crédito y gift cards

- **Comprobante interno imprimible:** al cerrar la venta, comprobante A4 (HTML + diálogo de impresión del navegador — sin PDF ni dependencias; "Guardar como PDF" desde el navegador). Botón en el modal de confirmación (tecla `P`) y en la ficha del pedido en el admin. **No es factura** (la fiscal se emite aparte desde el admin/ARCA).
- **Crédito en tienda:** al devolver se emite un crédito al portador con saldo. Se **canjea en el mostrador** (parcial o total) **y en el checkout web** (como cupón virtual en "Add coupons"; débito atómico al confirmar la orden, con reintegro si el pago no prospera). Débito atómico compartido web ↔ POS.
- **Gift cards:** el mismo instrumento se emite **a mano** (admin), **vendiéndolo en el mostrador** o **como producto web**. Un solo producto con **denominaciones fijas configurables** + **monto manual**. Al pagarse la orden genera el crédito y entrega el código (email en la web, modal en el POS). Admin en **Créditos (POS)**; vigencia configurable (default 30 días).

---

## Manual de mostrador

El plugin trae un **manual de operatoria** para el personal (para el handoff), servido desde el propio plugin — sin depender de nada externo. Accesos:

- **Admin → "Manual (POS)"** (visible también para el rol cajero).
- Enlace **"Manual ↗"** en la barra del POS.
- URL directa `?keytill_manual=1` (gateada por permiso).

Cubre las 13 operatorias paso a paso + referencia de teclas. Imprimible (Ctrl/Cmd + P) para dejar una copia en la caja.

---

## Estructura del código

```
keytill.php                      Bootstrap: header, activación (roles/caps), HPOS, shortcode [keytill],
                                gate de acceso, template en blanco, encolado de assets, registro REST/CLI.
includes/
  class-keytill-rest.php          API REST keytill/v1 (productos, órdenes, caja, crédito, consola admin).
  class-keytill-cash.php          Arqueo de caja (CPT keytill_cash_session).
  class-keytill-customers.php     Clientes (usuarios WP rol customer); el POS no manda emails.
  class-keytill-print.php         Comprobante interno imprimible (HTML A4).
  class-keytill-manual.php        Manual de mostrador (página autocontenida).
  class-keytill-cli.php           Comando `wp keytill seed-demo` (solo bajo WP-CLI).
  keytill-demo-products.php       Catálogo demo (18 productos) que usa el seeder.
  credito/
    class-keytill-credit.php          Motor de crédito en tienda (tablas propias, emisión/canje atómico, ledger).
    class-keytill-credit-web.php      Canje en el checkout web (cupón virtual + reserva atómica).
    class-keytill-credit-product.php  Gift cards como producto.
templates/
  blank-fullscreen.php          Página en blanco (sin header/footer del theme).
  pos-app.php                   Markup de la consola (Alpine).
  manual.php                    Manual de mostrador.
assets/
  css/tokens.css                Design tokens (fuente de verdad de estilos).
  css/pos.css                   Estilos del POS.
  js/alpine.min.js              Alpine 3.14.1 (vendorizado, NO CDN).
  js/pos.js                     Componente de venta + store del shell (Alpine).
  js/pos-orders.js              Pestaña Pedidos.
  js/pos-products.js            Pestaña Productos + editor.
  js/pos-customers.js           Pestaña Clientes.
```

### API REST (`keytill/v1`)

Mismo origen, autenticada con nonce `X-WP-Nonce`. Permisos: lectura = `keytill_operate`, escritura de productos = `keytill_manage`.

- **Catálogo/venta:** `GET /products`, `POST /orders`, `GET /orders/lookup`, `POST /orders/refund`.
- **Consola · Pedidos:** `GET /orders/list`, `GET /orders/filters`, `GET /orders/{id}`.
- **Consola · Productos:** `GET|POST /admin/products`, `GET /admin/products/meta`, `GET|PATCH|DELETE /admin/products/{id}`, `POST /admin/products/{id}/variations`, `GET|POST /admin/attributes`, `POST /admin/attributes/{id}/terms`, `POST /admin/media`.
- **Consola · Clientes:** `GET|POST /customers`, `GET /customers/list`, `GET|PATCH /customers/{id}`.
- **Caja/crédito:** `GET /cash/current`, `POST /cash/open|withdraw|close`, `GET /credit/lookup`.

### Marcas en Woo

Órdenes del POS: `created_via = keytill`, meta `_keytill`, `_keytill_cajero_*`, `_keytill_session`, etc. El crédito en tienda usa tablas propias (`wp_keytill_credits`, `wp_keytill_credit_ledger`). La sesión de caja es el CPT `keytill_cash_session`.

---

## Desarrollo

- **Sin build.** Alpine está vendorizado y el CSS es plano; se edita y se recarga. Al versionar assets se usa la constante `KEYTILL_VERSION` (subir la versión al cambiar JS/CSS para bustear caché); la versión de Alpine está hardcodeada en el `wp_enqueue_script` (actualizarla a mano si se cambia el vendor).
- **Seguridad:** todo endpoint valida capability + nonce, sanea la entrada y escapa la salida. Sin SQL directo salvo el módulo de crédito (tablas propias con `$wpdb->prepare`).
- **Seeder demo:** `wp keytill seed-demo` solo se carga bajo WP-CLI. Es una herramienta de desarrollo; evaluar si debe viajar a producción.
- Las notas internas de arquitectura, plan y estado (`PLAN.md` / `STATUS.md`) se mantienen en el repositorio privado del proyecto, no en este.

---

## Alcance

Incluye: venta, cobro (efectivo/tarjeta/transferencia/QR con vuelto), cliente fiscal, descuento, arqueo de caja (apertura/retiros/cierre), devoluciones (refund + reingreso de stock, de ventas del POS **o de la web**), comprobante imprimible, crédito en tienda y gift cards, y la consola de administración (Pedidos/Productos/Clientes).

**Pendiente (deuda anotada):** anular automáticamente el crédito al devolver una venta de gift card; refund **parcial** en el admin de órdenes web pagadas con crédito.

**Fuera de alcance (MVP):** facturación fiscal automática (se emite aparte desde el admin/ARCA), offline, multi-caja, impresora térmica, integración con posnet.

---

## Licencia

Plugin a medida para **Keytill** — uso privado. Sin licencia pública.
