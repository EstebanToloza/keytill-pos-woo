<?php
/**
 * Manual de operatoria de mostrador — página autocontenida (sin theme).
 * La sirve KEYTILL_Manual::maybe_render() en `?keytill_manual=1`. Contenido
 * estático (HTML+CSS+un poco de JS para el tema claro/oscuro); no recibe datos.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual de Mostrador · Keytill POS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
  :root {
    --paper:#F4F6F9; --surface:#FFFFFF; --surface-2:#EDF1F7;
    --ink:#10192A; --ink-soft:#45526A; --ink-faint:#8291A6;
    --line:#DBE2EC; --line-strong:#C3CDDB;
    --brand:#2456E6; --brand-strong:#1B3FB0; --brand-tint:#E5ECFD;
    --warn:#9A5B00; --warn-bg:#FCF1DE; --warn-line:#EFCF95;
    --ok:#1C7A44; --ok-bg:#E1F3E9; --ok-line:#AFDBC1;
    --danger:#B4362B; --danger-bg:#FBE7E4; --danger-line:#F0C1BB;
    --key-face:#FFFFFF; --key-edge:#B9C3D3; --key-ink:#1B2740;
    --shadow:0 1px 2px rgba(16,25,42,.06),0 8px 24px rgba(16,25,42,.06);
    --font-display:'Archivo',system-ui,sans-serif;
    --font-body:'IBM Plex Sans',system-ui,sans-serif;
    --font-mono:'IBM Plex Mono',ui-monospace,monospace;
    color-scheme:light dark;
  }
  @media (prefers-color-scheme:dark){
    :root:not([data-theme="light"]){
      --paper:#0C121E; --surface:#131D2C; --surface-2:#1B2739;
      --ink:#EAF0F8; --ink-soft:#A9B6C9; --ink-faint:#6C7B92;
      --line:#26344A; --line-strong:#32425C;
      --brand:#5E8CFF; --brand-strong:#8FADFF; --brand-tint:#172439;
      --warn:#E7A64F; --warn-bg:#291F10; --warn-line:#4B3A1D;
      --ok:#5CC68C; --ok-bg:#122619; --ok-line:#22432E;
      --danger:#F0796D; --danger-bg:#2C1613; --danger-line:#4A241F;
      --key-face:#1E2A3E; --key-edge:#0B1320; --key-ink:#DCE6F5;
      --shadow:0 1px 2px rgba(0,0,0,.3),0 10px 30px rgba(0,0,0,.35);
    }
  }
  :root[data-theme="dark"]{
    --paper:#0C121E; --surface:#131D2C; --surface-2:#1B2739;
    --ink:#EAF0F8; --ink-soft:#A9B6C9; --ink-faint:#6C7B92;
    --line:#26344A; --line-strong:#32425C;
    --brand:#5E8CFF; --brand-strong:#8FADFF; --brand-tint:#172439;
    --warn:#E7A64F; --warn-bg:#291F10; --warn-line:#4B3A1D;
    --ok:#5CC68C; --ok-bg:#122619; --ok-line:#22432E;
    --danger:#F0796D; --danger-bg:#2C1613; --danger-line:#4A241F;
    --key-face:#1E2A3E; --key-edge:#0B1320; --key-ink:#DCE6F5;
    --shadow:0 1px 2px rgba(0,0,0,.3),0 10px 30px rgba(0,0,0,.35);
  }

  *{box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--font-body);
    font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased;}
  img{max-width:100%}
  a{color:var(--brand);text-underline-offset:2px}

  .wrap{max-width:1180px;margin:0 auto;padding:0 24px}

  .mast{border-bottom:2px solid var(--ink);background:linear-gradient(180deg,var(--surface),var(--paper));}
  .mast__in{max-width:1180px;margin:0 auto;padding:38px 24px 30px;}
  .brandbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
  .brandbar__id{display:flex;align-items:center;gap:12px}
  .logo{font-family:var(--font-display);font-weight:800;letter-spacing:.14em;font-size:15px;
    color:var(--surface);background:var(--ink);padding:6px 11px;border-radius:5px;line-height:1}
  .kicker{font-family:var(--font-mono);font-size:12px;font-weight:600;letter-spacing:.22em;
    text-transform:uppercase;color:var(--ink-faint)}
  h1{font-family:var(--font-display);font-weight:800;letter-spacing:-.01em;
    font-size:clamp(32px,5vw,52px);line-height:1.02;margin:20px 0 10px;text-wrap:balance;max-width:16ch}
  .lede{font-size:18px;color:var(--ink-soft);max-width:60ch;margin:0}
  .theme-btn{font-family:var(--font-mono);font-size:12px;font-weight:600;letter-spacing:.06em;
    color:var(--ink-soft);background:var(--surface);border:1px solid var(--line-strong);
    border-radius:999px;padding:7px 14px;cursor:pointer;display:inline-flex;gap:7px;align-items:center}
  .theme-btn:hover{border-color:var(--brand);color:var(--brand)}
  .theme-btn:focus-visible{outline:2px solid var(--brand);outline-offset:2px}

  .legend{margin-top:26px;display:flex;flex-wrap:wrap;gap:10px}
  .legend__item{display:flex;align-items:center;gap:9px;background:var(--surface);
    border:1px solid var(--line);border-radius:10px;padding:8px 12px 8px 8px}
  .legend__lbl{font-size:13px;color:var(--ink-soft);font-weight:500}

  kbd,.key{font-family:var(--font-mono);font-weight:600;font-size:.82em;line-height:1;
    color:var(--key-ink);background:var(--key-face);
    border:1px solid var(--key-edge);border-bottom-width:3px;border-radius:6px;
    padding:5px 8px;display:inline-block;white-space:nowrap;min-width:1.4em;text-align:center}
  .key--brand{color:#fff;background:var(--brand);border-color:var(--brand-strong)}
  .key--lg{font-size:.95em;padding:7px 11px}

  .shell{display:grid;grid-template-columns:1fr;gap:0}
  @media (min-width:960px){.shell{grid-template-columns:236px minmax(0,1fr);gap:48px;align-items:start}}
  .toc{padding:32px 0 40px}
  @media (min-width:960px){.toc{position:sticky;top:0;max-height:100vh;overflow-y:auto;padding:44px 0}}
  .toc__group{margin-bottom:20px}
  .toc__h{font-family:var(--font-mono);font-size:11px;font-weight:600;letter-spacing:.18em;
    text-transform:uppercase;color:var(--ink-faint);margin:0 0 8px}
  .toc a{display:block;text-decoration:none;color:var(--ink-soft);font-size:14.5px;
    padding:5px 0 5px 12px;border-left:2px solid var(--line);transition:.15s}
  .toc a:hover{color:var(--brand);border-color:var(--brand)}
  .toc a .num{font-family:var(--font-mono);font-size:12px;color:var(--ink-faint);margin-right:7px}

  main{padding:20px 0 80px;min-width:0}
  @media (min-width:960px){main{padding:44px 0 100px}}

  .op{padding:34px 0;border-top:1px solid var(--line)}
  .op:first-child{border-top:none;padding-top:8px}
  .op__head{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:8px}
  .op__no{font-family:var(--font-mono);font-size:13px;font-weight:600;color:var(--brand);
    background:var(--brand-tint);border-radius:7px;padding:6px 10px;line-height:1;margin-top:4px}
  .op__title{font-family:var(--font-display);font-weight:700;font-size:clamp(23px,3vw,30px);
    line-height:1.1;margin:0;letter-spacing:-.01em;flex:1;min-width:0;text-wrap:balance}
  .op__keys{display:flex;gap:6px;align-items:center;margin-top:2px}
  .op__when{color:var(--ink-soft);font-size:16.5px;margin:2px 0 18px;max-width:64ch}
  .section-band{font-family:var(--font-mono);font-size:12px;font-weight:600;letter-spacing:.2em;
    text-transform:uppercase;color:var(--ink-faint);margin:44px 0 4px;display:flex;align-items:center;gap:12px}
  .section-band::after{content:"";flex:1;height:1px;background:var(--line)}
  .section-band:first-child{margin-top:0}

  ol.steps{list-style:none;counter-reset:s;margin:0;padding:0;display:flex;flex-direction:column;gap:13px}
  ol.steps>li{counter-increment:s;position:relative;padding-left:44px;min-height:30px}
  ol.steps>li::before{content:counter(s);position:absolute;left:0;top:-1px;
    width:30px;height:30px;border-radius:50%;background:var(--ink);color:var(--surface);
    font-family:var(--font-mono);font-weight:600;font-size:14px;display:flex;align-items:center;justify-content:center}
  ol.steps>li strong{font-weight:600}

  ul.plain{margin:14px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:9px}
  ul.plain>li{position:relative;padding-left:20px;color:var(--ink-soft)}
  ul.plain>li::before{content:"";position:absolute;left:2px;top:11px;width:6px;height:6px;border-radius:50%;background:var(--brand)}

  .note{border:1px solid var(--line);border-left-width:4px;border-radius:8px;
    background:var(--surface);padding:15px 18px;margin:18px 0 0;font-size:15.5px}
  .note p{margin:0}
  .note p + p{margin-top:8px}
  .note__tag{font-family:var(--font-mono);font-size:12px;font-weight:600;letter-spacing:.08em;
    text-transform:uppercase;display:block;margin-bottom:5px}
  .note--warn{border-color:var(--warn-line);border-left-color:var(--warn);background:var(--warn-bg)}
  .note--warn .note__tag{color:var(--warn)}
  .note--ok{border-color:var(--ok-line);border-left-color:var(--ok);background:var(--ok-bg)}
  .note--ok .note__tag{color:var(--ok)}
  .note--info{border-color:var(--brand);border-left-color:var(--brand);background:var(--brand-tint)}
  .note--info .note__tag{color:var(--brand-strong)}

  code{font-family:var(--font-mono);font-size:.88em;background:var(--surface-2);padding:1px 6px;border-radius:4px;color:var(--ink)}
  .money{font-family:var(--font-mono);font-variant-numeric:tabular-nums;font-weight:600}

  .tablewrap{overflow-x:auto;margin:18px 0 0;border:1px solid var(--line);border-radius:10px}
  table{width:100%;border-collapse:collapse;font-size:15px;min-width:520px}
  th,td{text-align:left;padding:12px 15px;border-bottom:1px solid var(--line);vertical-align:top}
  thead th{background:var(--surface-2);font-family:var(--font-mono);font-size:12px;font-weight:600;
    letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft)}
  tbody tr:last-child td{border-bottom:none}
  td .money{color:var(--ink)}
  .pill{font-family:var(--font-mono);font-size:12px;font-weight:600;padding:3px 9px;border-radius:999px;white-space:nowrap}
  .pill--up{background:var(--ok-bg);color:var(--ok)}
  .pill--down{background:var(--warn-bg);color:var(--warn)}
  .pill--role{background:var(--surface-2);color:var(--ink-soft)}

  .example{background:var(--surface);border:1px dashed var(--line-strong);border-radius:10px;padding:16px 18px;margin:18px 0 0}
  .example__h{font-family:var(--font-mono);font-size:12px;font-weight:600;letter-spacing:.1em;
    text-transform:uppercase;color:var(--ink-faint);margin:0 0 10px}
  .flow{display:flex;flex-wrap:wrap;gap:8px 6px;align-items:center;font-size:15px}
  .flow .step{background:var(--surface-2);border:1px solid var(--line);border-radius:7px;padding:6px 11px}
  .flow .arr{color:var(--ink-faint);font-weight:700}
  .flow .zero{color:var(--ok);font-weight:600;font-family:var(--font-mono)}

  .ref .key{margin-right:4px}

  footer{border-top:2px solid var(--ink);background:var(--surface)}
  .foot{max-width:1180px;margin:0 auto;padding:30px 24px 44px;color:var(--ink-soft);font-size:14.5px}
  .foot strong{color:var(--ink)}
  .foot__grid{display:grid;gap:18px;grid-template-columns:1fr;margin-top:6px}
  @media (min-width:720px){.foot__grid{grid-template-columns:repeat(3,1fr)}}
  .foot__grid h4{font-family:var(--font-mono);font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-faint);margin:0 0 6px}

  :where(a,button):focus-visible{outline:2px solid var(--brand);outline-offset:3px;border-radius:3px}
  :target{scroll-margin-top:20px}
  @media (prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}

  @media print{
    .toc,.theme-btn,.legend{display:none!important}
    .shell{grid-template-columns:1fr;gap:0}
    body{background:#fff;font-size:12px}
    .op{page-break-inside:avoid}
    .mast{border-color:#000}
  }
</style>
</head>
<body>

<header class="mast">
  <div class="mast__in">
    <div class="brandbar">
      <div class="brandbar__id">
        <span class="logo">KEYTILL</span>
        <span class="kicker">POS &middot; Manual de mostrador</span>
      </div>
      <button class="theme-btn" id="themeToggle" type="button" aria-label="Cambiar tema">
        <span id="themeIcon">&#9680;</span><span id="themeLabel">Tema</span>
      </button>
    </div>
    <h1>Cómo se opera la caja</h1>
    <p class="lede">Todo lo que se hace desde el mostrador, paso a paso: abrir la caja, vender, cobrar, devolver, cambiar, cerrar el turno y administrar el catálogo. Operado por teclado — cada tecla de función dispara una acción.</p>

    <div class="legend" aria-label="Teclas de función">
      <span class="legend__item"><kbd class="key key--brand">F2</kbd><span class="legend__lbl">Cobrar</span></span>
      <span class="legend__item"><kbd class="key">F4</kbd><span class="legend__lbl">Cliente</span></span>
      <span class="legend__item"><kbd class="key">F7</kbd><span class="legend__lbl">Devolución</span></span>
      <span class="legend__item"><kbd class="key">F8</kbd><span class="legend__lbl">Descuento</span></span>
      <span class="legend__item"><kbd class="key">F9</kbd><span class="legend__lbl">Cerrar caja</span></span>
      <span class="legend__item"><kbd class="key">F10</kbd><span class="legend__lbl">Retiro</span></span>
      <span class="legend__item"><kbd class="key">ESC</kbd><span class="legend__lbl">Cancelar</span></span>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="shell">
    <nav class="toc" aria-label="Contenido">
      <div class="toc__group">
        <p class="toc__h">Caja y venta</p>
        <a href="#antes"><span class="num">00</span>Antes de empezar</a>
        <a href="#abrir"><span class="num">01</span>Abrir la caja</a>
        <a href="#vender"><span class="num">02</span>Vender</a>
        <a href="#cobrar"><span class="num">03</span>Cobrar</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Cliente y ajustes</p>
        <a href="#cliente"><span class="num">04</span>Asignar cliente</a>
        <a href="#descuento"><span class="num">05</span>Descuento</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Devoluciones y crédito</p>
        <a href="#devolucion"><span class="num">06</span>Devoluciones y cambios</a>
        <a href="#credito"><span class="num">07</span>Crédito y gift cards</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Cierre de turno</p>
        <a href="#retiro"><span class="num">08</span>Retiro de efectivo</a>
        <a href="#cierre"><span class="num">09</span>Cerrar la caja</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Consola</p>
        <a href="#pedidos"><span class="num">10</span>Pedidos</a>
        <a href="#productos"><span class="num">11</span>Productos</a>
        <a href="#clientes"><span class="num">12</span>Clientes</a>
        <a href="#cajabanco"><span class="num">13</span>Caja / Banco</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Referencia</p>
        <a href="#atajos"><span class="num">&#9733;</span>Teclas y avisos</a>
      </div>
    </nav>

    <main>
      <section class="op" id="antes">
        <div class="op__head"><span class="op__no">00</span><h2 class="op__title">Antes de empezar</h2></div>
        <p class="op__when">Tres cosas que conviene tener claras antes de tocar una tecla.</p>

        <p class="section-band">El campo de comando</p>
        <p style="color:var(--ink-soft);margin:0">La caja tiene un cuadro grande que está <strong>siempre enfocado</strong>: ahí se escanea o se busca. Funciona como lector de código de barras — el <strong>SKU es el código</strong>. También se puede escribir el nombre del producto y elegir con las flechas.</p>
        <ul class="plain">
          <li>Escaneá o escribí y presioná <kbd class="key">ENTER</kbd> para agregar al ticket.</li>
          <li>Para varias unidades de una: escribí <code>N*</code> antes del código (ej. <code>3*</code> y escaneás = 3 unidades).</li>
          <li>Un producto con talles/colores tiene <strong>un código por variación</strong>: cada talle se escanea y descuenta su propio stock.</li>
        </ul>

        <p class="section-band">Quién puede hacer qué</p>
        <div class="tablewrap"><table>
          <thead><tr><th>Rol</th><th>Puede</th></tr></thead>
          <tbody>
            <tr><td><span class="pill pill--role">Cajero</span></td><td>Vender, cobrar, asignar/crear clientes, hacer devoluciones, retirar y cerrar caja, y <strong>ver</strong> las pestañas de la consola.</td></tr>
            <tr><td><span class="pill pill--role">Encargado / Admin</span></td><td>Todo lo del cajero, y además <strong>editar productos</strong>: precio, stock, activar/desactivar y dar de alta.</td></tr>
          </tbody>
        </table></div>

        <div class="note note--warn"><span class="note__tag">Requisito</span>
          <p>No se puede vender con la caja cerrada. Lo primero de cada turno es <a href="#abrir">abrir la caja</a> con el fondo inicial. Usar siempre <strong>Google Chrome</strong>.</p>
        </div>
      </section>

      <section class="op" id="abrir">
        <div class="op__head"><span class="op__no">01</span><h2 class="op__title">Abrir la caja</h2></div>
        <p class="op__when">Al inicio del turno. Registra el efectivo con el que arranca el cajón (fondo inicial) para poder arquear al cierre.</p>
        <ol class="steps">
          <li>Al entrar sin caja abierta aparece la pantalla <strong>Abrir caja</strong>.</li>
          <li>Escribí el <strong>fondo inicial</strong> de efectivo (lo que hay en el cajón para dar vuelto).</li>
          <li>Presioná <kbd class="key key--brand">ENTER</kbd>. La caja queda abierta y ya podés vender.</li>
        </ol>
        <div class="note note--info"><span class="note__tag">Una sola caja</span>
          <p>Hay una caja a la vez. Si ya hay una abierta, el sistema no deja abrir otra: primero se cierra la anterior.</p>
        </div>
      </section>

      <section class="op" id="vender">
        <div class="op__head"><span class="op__no">02</span><h2 class="op__title">Vender: armar el ticket</h2></div>
        <p class="op__when">Cargar los productos que lleva el cliente antes de cobrar.</p>
        <ol class="steps">
          <li>Escaneá el producto (o escribí el nombre y elegí con <kbd class="key">↑</kbd> <kbd class="key">↓</kbd> + <kbd class="key">ENTER</kbd>). Se agrega al ticket.</li>
          <li>Para elegir una línea del ticket usá <kbd class="key">↑</kbd> <kbd class="key">↓</kbd>; para cambiar la cantidad, <kbd class="key">+</kbd> <kbd class="key">−</kbd>.</li>
          <li>Para quitar la línea elegida: <kbd class="key">SUPR</kbd>.</li>
          <li>Cuando el ticket esté completo, cobrá con <kbd class="key key--brand">F2</kbd>.</li>
        </ol>
        <div class="note"><span class="note__tag">Nota</span>
          <p>El total se ve siempre a la derecha. El stock que muestra es una referencia; el descuento real de stock ocurre al cobrar.</p>
        </div>
      </section>

      <section class="op" id="cobrar">
        <div class="op__head"><span class="op__no">03</span><h2 class="op__title">Cobrar</h2><div class="op__keys"><kbd class="key key--brand key--lg">F2</kbd></div></div>
        <p class="op__when">Elegir el medio de pago, cobrar y (si hace falta) calcular el vuelto.</p>
        <ol class="steps">
          <li>Con <kbd class="key key--brand">F2</kbd> se abre el cobro. Elegí el medio con el número:
            <ul class="plain" style="margin-top:10px">
              <li><kbd class="key">1</kbd> Efectivo &nbsp; <kbd class="key">2</kbd> Tarjeta &nbsp; <kbd class="key">3</kbd> Transferencia &nbsp; <kbd class="key">4</kbd> QR</li>
              <li><kbd class="key">C</kbd> Crédito en tienda (canje de un código — ver <a href="#credito">§07</a>).</li>
            </ul>
          </li>
          <li>Si es <strong>efectivo</strong>, tipeá lo que recibís y el sistema muestra el <strong>vuelto</strong>.</li>
          <li>Confirmá con <kbd class="key key--brand">ENTER</kbd>. La venta queda registrada y el stock descontado.</li>
          <li>En el resumen de venta: <kbd class="key">P</kbd> imprime el comprobante &nbsp;·&nbsp; <kbd class="key">C</kbd> imprime el <strong>ticket de cambio</strong> &nbsp;·&nbsp; <kbd class="key">ENTER</kbd> continúa. El resumen <strong>no se cierra</strong> al imprimir, así que podés sacar los dos.</li>
        </ol>
        <div class="note note--info"><span class="note__tag">Ticket de cambio (para regalos)</span>
          <p>Es el mismo ticket pero <strong>sin precios</strong>, para meter en un regalo. Lleva un <strong>código de barras</strong>: cuando traen la prenda a cambiar, en <strong>Devolución</strong> (<a href="#devolucion">§06</a>) lo <strong>escaneás</strong> y carga la venta directo. Se puede reimprimir después desde <strong>Pedidos</strong>.</p>
        </div>
        <div class="note note--info"><span class="note__tag">Pago combinado</span>
          <p>Se puede pagar parte con un <strong>crédito en tienda</strong> (tecla <kbd class="key">C</kbd>) y el resto con otro medio. El sistema calcula solo cuánto cubre el crédito y cuánto queda por cobrar.</p>
        </div>
        <div class="note note--warn"><span class="note__tag">No es factura</span>
          <p>El comprobante que imprime el POS es <strong>interno, no fiscal</strong> (“no válido como factura”). La factura fiscal se emite aparte desde la administración.</p>
        </div>
      </section>

      <section class="op" id="cliente">
        <div class="op__head"><span class="op__no">04</span><h2 class="op__title">Asignar un cliente</h2><div class="op__keys"><kbd class="key key--lg">F4</kbd></div></div>
        <p class="op__when">Opcional. Sirve para dejar registrado quién compró (y sus datos fiscales). Una venta puede ser anónima.</p>
        <ol class="steps">
          <li>Con <kbd class="key">F4</kbd> se abre el buscador. Escribí <strong>nombre, email o DNI</strong>.</li>
          <li>Elegí de la lista con <kbd class="key">↑</kbd> <kbd class="key">↓</kbd> y asigná con <kbd class="key">ENTER</kbd>.</li>
          <li>Si no existe, presioná <kbd class="key">TAB</kbd> para <strong>crear uno nuevo</strong>: nombre, email o DNI (al menos uno), y condición fiscal (por defecto <em>Consumidor Final</em>). <kbd class="key">ENTER</kbd> guarda.</li>
          <li>Para quitar el cliente asignado: <kbd class="key">SUPR</kbd> con el buscador vacío.</li>
        </ol>
        <div class="note note--ok"><span class="note__tag">Sin emails</span>
          <p>Los clientes del POS son los mismos de la tienda web, pero <strong>el POS nunca manda emails</strong> — ni al crear el cliente ni al cerrar la venta. Los emails son solo para las compras online.</p>
        </div>
        <div class="note"><span class="note__tag">Sin duplicados</span>
          <p>Si el email o el DNI ya existen, el sistema no crea otro cliente: te ofrece el existente para seleccionarlo.</p>
        </div>
      </section>

      <section class="op" id="descuento">
        <div class="op__head"><span class="op__no">05</span><h2 class="op__title">Aplicar un descuento</h2><div class="op__keys"><kbd class="key key--lg">F8</kbd></div></div>
        <p class="op__when">Un descuento porcentual sobre el total del ticket.</p>
        <ol class="steps">
          <li>Con <kbd class="key">F8</kbd> se abre el descuento.</li>
          <li>Escribí el <strong>porcentaje</strong> (0 a 100). El nuevo total se previsualiza.</li>
          <li><kbd class="key key--brand">ENTER</kbd> aplica; <kbd class="key">ESC</kbd> cancela.</li>
        </ol>
      </section>

      <section class="op" id="devolucion">
        <div class="op__head"><span class="op__no">06</span><h2 class="op__title">Devoluciones y cambios</h2><div class="op__keys"><kbd class="key key--lg">F7</kbd></div></div>
        <p class="op__when">Devolver productos de una venta (del POS <em>o</em> de la web) reingresando el stock, y hacer cambios. Un cambio se resuelve como <strong>devolución + venta nueva</strong>: no se modifica ni se borra la orden original.</p>

        <p class="section-band">Devolver</p>
        <ol class="steps">
          <li>Con <kbd class="key">F7</kbd>, ingresá el <strong>N° de venta</strong> y <kbd class="key">ENTER</kbd> para buscarla.</li>
          <li>Elegí la línea con <kbd class="key">↑</kbd> <kbd class="key">↓</kbd> y la cantidad a devolver con <kbd class="key">+</kbd> <kbd class="key">−</kbd>.</li>
          <li>Elegí el <strong>medio de reintegro</strong>: <kbd class="key">C</kbd> crédito en tienda &nbsp;·&nbsp; <kbd class="key">E</kbd> efectivo del cajón &nbsp;·&nbsp; <kbd class="key">T</kbd> transferencia (se devuelve por transferencia y <strong>resta de la caja digital</strong>, no del cajón).</li>
          <li><kbd class="key key--brand">ENTER</kbd> procesa. Reingresa el stock y, según el medio, emite un código de crédito o descuenta del cajón.</li>
        </ol>

        <p class="section-band">Hacer un cambio</p>
        <p style="color:var(--ink-soft);margin:0">La forma limpia es <strong>devolver a crédito</strong> y usar ese crédito en la venta nueva. Así la plata queda contabilizada en las dos patas y la caja no se descuadra.</p>

        <div class="example">
          <p class="example__h">Mismo producto, otro talle · mismo precio</p>
          <div class="flow">
            <span class="step"><kbd class="key">F7</kbd> devolver talle M → crédito</span>
            <span class="arr">→</span>
            <span class="step"><kbd class="key">F2</kbd> vender talle L, canjear crédito <kbd class="key">C</kbd></span>
            <span class="arr">→</span>
            <span class="zero">Total $0 · sin mover el cajón</span>
          </div>
        </div>

        <p style="color:var(--ink-soft);margin:18px 0 0">Cuando el producto nuevo <strong>vale distinto</strong>:</p>
        <div class="tablewrap"><table>
          <thead><tr><th>Diferencia</th><th>Cómo se maneja</th></tr></thead>
          <tbody>
            <tr><td><span class="pill pill--up">Nuevo cuesta más</span></td><td>El crédito cubre una parte y <strong>cobrás el resto</strong> por cualquier medio (efectivo/tarjeta/QR). El cobro calcula solo el faltante y el vuelto.</td></tr>
            <tr><td><span class="pill pill--down">Nuevo cuesta menos</span></td><td><strong>Opción A —</strong> queda <strong>saldo en el código</strong> de crédito y el cliente se lo lleva para otra compra.<br><strong>Opción B —</strong> si querés devolver la diferencia <em>en efectivo</em>, hacé la devolución original en <strong>efectivo</strong> (no crédito) y cobrás el producto nuevo aparte.</td></tr>
          </tbody>
        </table></div>

        <div class="note note--warn"><span class="note__tag">Importante · antes de devolver</span>
          <p>No hay forma de “sacar en efectivo” el saldo que sobra de un crédito. Por eso <strong>preguntá antes</strong>: <em>¿lo cambia por algo, o quiere la plata?</em> — y elegí el medio de reintegro en consecuencia (crédito si va a comprar otra cosa, efectivo si quiere la diferencia).</p>
        </div>
        <div class="note"><span class="note__tag">Por qué no se “edita” la orden</span>
          <p>Modificar una venta ya cobrada rompería el control de caja y el stock. La devolución es la vía correcta: deja registro, reingresa stock y mueve la plata de forma contabilizada.</p>
        </div>
      </section>

      <section class="op" id="credito">
        <div class="op__head"><span class="op__no">07</span><h2 class="op__title">Crédito en tienda y gift cards</h2></div>
        <p class="op__when">El crédito en tienda es un instrumento <strong>al portador</strong>: un código tipo <code>HM-4K7P-9QX2</code> con saldo. Se emite al devolver, o se vende como gift card.</p>

        <p class="section-band">Canjear un crédito</p>
        <ul class="plain">
          <li>En el cobro (<kbd class="key">F2</kbd>) presioná <kbd class="key">C</kbd> e ingresá el código. Se valida el saldo.</li>
          <li>Cubre <strong>total o parcial</strong>: si no alcanza, el resto se paga con otro medio.</li>
          <li>El saldo no usado <strong>queda en el mismo código</strong> para la próxima.</li>
        </ul>

        <p class="section-band">Vender una gift card</p>
        <ol class="steps">
          <li>Escaneá o buscá el producto <strong>gift card</strong> y agregalo al ticket.</li>
          <li>Elegí una <strong>denominación fija</strong> (ej. <span class="money">$ 10.000</span>) o tipeá un <strong>monto libre</strong>.</li>
          <li>Cobrá normal con <kbd class="key key--brand">F2</kbd>. Al confirmar, el sistema <strong>genera el código</strong> y lo muestra para entregárselo al cliente (también sale en el comprobante).</li>
        </ol>
        <div class="note note--info"><span class="note__tag">Vigencia</span>
          <p>Los créditos tienen vencimiento (por defecto 30 días). El vencimiento se ve al consultar el código. La administración de créditos vive en el panel, en <strong>Créditos (POS)</strong>.</p>
        </div>
      </section>

      <section class="op" id="retiro">
        <div class="op__head"><span class="op__no">08</span><h2 class="op__title">Retiro de efectivo</h2><div class="op__keys"><kbd class="key key--lg">F10</kbd></div></div>
        <p class="op__when">Sacar efectivo del cajón durante el turno (pago a proveedor, depósito, etc.). Queda registrado para que el arqueo cierre.</p>
        <ol class="steps">
          <li>Con <kbd class="key">F10</kbd>, escribí el <strong>monto</strong> y el <strong>motivo</strong> (<kbd class="key">TAB</kbd> entre campos).</li>
          <li><kbd class="key key--brand">ENTER</kbd> registra el retiro. El efectivo esperado del cierre se ajusta solo.</li>
        </ol>
        <div class="note"><span class="note__tag"><span class="pill pill--role">Encargado / Admin</span> Origen del retiro</span>
          <p>Con permiso de gestión, el retiro deja elegir el <strong>origen</strong>: <strong>caja física</strong> (efectivo del cajón) o <strong>caja digital</strong> (sale de la cuenta bancaria, ej. una transferencia a un proveedor). El cajero común solo retira efectivo. Ver <a href="#cajabanco">§13</a>.</p>
        </div>
      </section>

      <section class="op" id="cierre">
        <div class="op__head"><span class="op__no">09</span><h2 class="op__title">Cerrar la caja (arqueo)</h2><div class="op__keys"><kbd class="key key--lg">F9</kbd></div></div>
        <p class="op__when">Al final del turno. Compara lo que el sistema espera en el cajón contra lo que contás físicamente.</p>
        <ol class="steps">
          <li>Con <kbd class="key">F9</kbd> se abre el arqueo. Muestra el <strong>desglose por medio</strong> y el <strong>efectivo esperado</strong>.</li>
          <li>Contá el efectivo del cajón y escribí el <strong>monto contado</strong>. Se calcula la <strong>diferencia</strong> (sobra / falta).</li>
          <li><kbd class="key key--brand">ENTER</kbd> cierra la caja y guarda el resumen del turno.</li>
        </ol>
        <div class="note"><span class="note__tag">Cómo se calcula el esperado</span>
          <p><span class="money">Fondo inicial</span> + <span class="money">ventas en efectivo</span> − <span class="money">retiros</span> − <span class="money">devoluciones en efectivo</span>. Tarjeta, transferencia, QR y crédito se listan aparte (no son efectivo del cajón).</p>
        </div>
      </section>

      <section class="op" id="pedidos">
        <p class="section-band">La consola · administrar sin salir del POS</p>
        <div class="op__head"><span class="op__no">10</span><h2 class="op__title">Pedidos</h2></div>
        <p class="op__when">Buscar y revisar cualquier pedido — del POS y de la web — sin entrar al panel.</p>
        <ol class="steps">
          <li>Entrá a la pestaña <strong>Pedidos</strong> (arriba).</li>
          <li>Buscá por <strong>N° de pedido</strong> o por <strong>nombre del cliente</strong>; filtrá por fecha, origen, medio, estado o cajero.</li>
          <li>Tocá un pedido para ver el detalle. Desde ahí: <strong>Reimprimir</strong> el comprobante o <strong>Devolver</strong> (abre el flujo de devolución de <a href="#devolucion">§06</a>).</li>
        </ol>
      </section>

      <section class="op" id="productos">
        <div class="op__head"><span class="op__no">11</span><h2 class="op__title">Productos</h2></div>
        <p class="op__when">Ver el catálogo y, con permiso de gestión, hacer ediciones rápidas. El cajero ve en <strong>solo lectura</strong>.</p>
        <ol class="steps">
          <li>Buscá por <strong>nombre o SKU</strong>; filtrá por categoría, estado o stock.</li>
          <li><span class="pill pill--role">Encargado / Admin</span> — editá <strong>precio</strong> y <strong>stock</strong> directo en la fila (se guarda al salir del campo o con <kbd class="key">ENTER</kbd>), y activá/desactivá con el interruptor.</li>
          <li>Un producto con variaciones (talle/color) se <strong>despliega</strong> para editar precio y stock de cada una.</li>
          <li>Botón <strong>Nuevo producto</strong> para un alta simple (nombre, SKU, precio, stock, categoría). Imágenes y variaciones se cargan desde el panel.</li>
        </ol>
        <div class="note"><span class="note__tag"><span class="pill pill--role">Encargado / Admin</span> Alta por escaneo</span>
          <p>Escaneá (o escribí) un <strong>código de barras de 12 dígitos</strong> en el buscador. Si <strong>ya existe</strong>, abre ese producto. Si <strong>no existe</strong>, aparece <strong>“crear / agregar desde el código”</strong>: el sistema decodifica <strong>color y talle</strong> del código y, si es de un <strong>modelo que ya cargaste</strong>, le agrega esa variación; si no, crea un <strong>producto nuevo</strong> con esa variación precargada. Solo completás <strong>nombre y precio</strong>.</p>
        </div>
      </section>

      <section class="op" id="clientes">
        <div class="op__head"><span class="op__no">12</span><h2 class="op__title">Clientes</h2></div>
        <p class="op__when">Administrar los clientes de la tienda desde el mostrador.</p>
        <ol class="steps">
          <li>Buscá por <strong>nombre, email o DNI</strong>.</li>
          <li>Abrí un cliente para ver sus <strong>estadísticas</strong> (pedidos, total gastado) y su <strong>historial de compras</strong> (con reimpresión de las ventas del POS).</li>
          <li><strong>Editá</strong> sus datos (nombre, email, DNI, condición fiscal) o creá uno nuevo. Igual que en la venta: sin duplicados y <strong>sin enviar emails</strong>.</li>
        </ol>
      </section>

      <section class="op" id="cajabanco">
        <div class="op__head"><span class="op__no">13</span><h2 class="op__title">Caja / Banco</h2><div class="op__keys"><span class="pill pill--role">Encargado / Admin</span></div></div>
        <p class="op__when">Una pestaña (solo gestión) que muestra las <strong>dos cajas</strong> con su saldo al día: la <strong>caja física</strong> (el efectivo del cajón) y la <strong>caja digital</strong> (la cuenta bancaria única a la que entran transferencias, QR, tarjeta y demás medios digitales). El cajero común no la ve.</p>

        <p class="section-band">Las dos cajas</p>
        <div class="tablewrap"><table>
          <thead><tr><th>Caja</th><th>Qué registra</th><th>Apertura</th></tr></thead>
          <tbody>
            <tr><td><strong>Física</strong></td><td>Efectivo del cajón. Es por cajero (cada uno abre la suya).</td><td>Se abre contando el <strong>fondo inicial</strong> (ver <a href="#abrir">§01</a>).</td></tr>
            <tr><td><strong>Digital</strong></td><td>La cuenta bancaria. Es <strong>una sola y global</strong> (compartida). Suma todo lo cobrado por medios no-efectivo.</td><td>El saldo <strong>se arrastra</strong>: la apertura = el saldo del último cierre. La primera vez se carga a mano.</td></tr>
          </tbody>
        </table></div>

        <p class="section-band">Abrir y cerrar la caja digital</p>
        <ol class="steps">
          <li>En la pestaña <strong>Caja / Banco</strong>, botón <strong>Abrir caja digital</strong>. La <strong>primera vez</strong> pedís el <strong>saldo inicial</strong> (cuánto hay hoy en la cuenta); después abre directo con el saldo del último cierre.</li>
          <li>Mientras está abierta: cada <strong>cobro por transferencia/QR/tarjeta</strong> suma solo, y podés cargar <strong>ingresos y retiros</strong> a mano.</li>
          <li><strong>Cerrar</strong> hace el corte: el saldo final queda como apertura del próximo período. <strong>No hace falta cerrarla todos los días</strong> — podés dejarla abierta; es un corte administrativo.</li>
        </ol>
        <div class="note"><span class="note__tag">No se pierde nada si está cerrada</span>
          <p>Aunque la caja digital esté cerrada, las ventas por transferencia <strong>igual se registran</strong> y aparecen en el próximo período al reabrirla. Al abrir tu caja física, si sos encargado/admin y la digital está cerrada, el POS te ofrece abrirla.</p>
        </div>

        <p class="section-band">Ingresos y retiros (con origen)</p>
        <ol class="steps">
          <li>En cada caja, botones <strong>Ingreso</strong> y <strong>Retiro</strong>: cargás <strong>monto</strong> y <strong>concepto</strong> (ej. “pago proveedor”, “aporte”).</li>
          <li>Un <strong>retiro de la digital</strong> = plata que sale de la cuenta (ej. transferís a un proveedor). Un <strong>ingreso</strong> = plata que entra que no es una venta.</li>
          <li>El retiro rápido desde <strong>Venta</strong> (<kbd class="key">F10</kbd>) también deja elegir el <strong>origen</strong> (física / digital) si sos gestión.</li>
        </ol>

        <p class="section-band">Conciliación · transferencias</p>
        <p class="op__when" style="margin:0 0 10px">Para controlar que una transferencia <strong>realmente entró</strong> al banco (y cazar comprobantes truchos).</p>
        <ol class="steps">
          <li>Abajo de la caja digital se listan las <strong>transferencias del período</strong> (venta, cliente, monto).</li>
          <li>Cotejá con tu homebanking y marcá cada una: <strong>✓ Verificada</strong> (entró) o <strong>✕ No llegó</strong>. Tocar de nuevo vuelve a <em>pendiente</em>.</li>
        </ol>

        <div class="note"><span class="note__tag">Histórico · dónde ver los cierres</span>
          <p>En el panel de WordPress → menú <strong>Keytill POS</strong>: <strong>Cajas (POS)</strong> (física) y <strong>Caja digital</strong>. Cada período se lista con sus totales; al hacer clic en la <strong>fecha de apertura</strong> se abre el <strong>detalle</strong> (movimientos con su motivo, desglose por medio y transferencias con su verificación).</p>
        </div>
      </section>

      <section class="op ref" id="atajos">
        <p class="section-band">Referencia rápida</p>
        <div class="op__head"><span class="op__no">&#9733;</span><h2 class="op__title">Teclas y avisos</h2></div>
        <div class="tablewrap"><table>
          <thead><tr><th>Tecla</th><th>Qué hace</th><th>Dónde</th></tr></thead>
          <tbody>
            <tr><td><kbd class="key key--brand">F2</kbd></td><td>Cobrar</td><td>Venta</td></tr>
            <tr><td><kbd class="key">F4</kbd></td><td>Asignar / crear cliente</td><td>Venta</td></tr>
            <tr><td><kbd class="key">F7</kbd></td><td>Devolución / cambio</td><td>Venta</td></tr>
            <tr><td><kbd class="key">F8</kbd></td><td>Descuento (%)</td><td>Venta</td></tr>
            <tr><td><kbd class="key">F9</kbd></td><td>Cerrar caja / arqueo</td><td>Venta</td></tr>
            <tr><td><kbd class="key">F10</kbd></td><td>Retiro de efectivo</td><td>Venta</td></tr>
            <tr><td><kbd class="key">ENTER</kbd></td><td>Agregar / confirmar</td><td>Todo</td></tr>
            <tr><td><kbd class="key">↑</kbd> <kbd class="key">↓</kbd></td><td>Elegir línea / resultado</td><td>Venta</td></tr>
            <tr><td><kbd class="key">+</kbd> <kbd class="key">−</kbd></td><td>Cantidad de la línea</td><td>Venta</td></tr>
            <tr><td><kbd class="key">SUPR</kbd></td><td>Quitar línea / cliente</td><td>Venta</td></tr>
            <tr><td><kbd class="key">P</kbd></td><td>Imprimir comprobante</td><td>Tras cobrar</td></tr>
            <tr><td><kbd class="key">C</kbd></td><td>Canjear crédito · reintegro a crédito</td><td>Cobro · Devolución</td></tr>
            <tr><td><kbd class="key">E</kbd></td><td>Reintegro en efectivo</td><td>Devolución</td></tr>
            <tr><td><kbd class="key">1</kbd>–<kbd class="key">4</kbd></td><td>Efectivo · Tarjeta · Transferencia · QR</td><td>Cobro</td></tr>
            <tr><td><kbd class="key">N</kbd><kbd class="key">*</kbd></td><td>N unidades del próximo código</td><td>Campo de comando</td></tr>
            <tr><td><kbd class="key">ESC</kbd></td><td>Cancelar / cerrar</td><td>Todo</td></tr>
          </tbody>
        </table></div>
      </section>
    </main>
  </div>
</div>

<footer>
  <div class="foot">
    <div class="foot__grid">
      <div><h4>No es factura</h4><p>El comprobante del POS es interno y <strong>no fiscal</strong>. La factura se emite aparte desde la administración.</p></div>
      <div><h4>Sin emails</h4><p>El POS <strong>no envía ningún email</strong>: ni al crear clientes ni al cerrar ventas. Eso es solo para la tienda web.</p></div>
      <div><h4>Navegador</h4><p>Usar <strong>Google Chrome</strong> a pantalla completa. La caja debe estar abierta para poder vender.</p></div>
    </div>
    <p style="margin-top:24px;color:var(--ink-faint);font-family:var(--font-mono);font-size:12px;letter-spacing:.04em">KEYTILL · POS de mostrador · Manual de operatoria</p>
  </div>
</footer>

<script>
  (function () {
    var root = document.documentElement, btn = document.getElementById('themeToggle'),
        icon = document.getElementById('themeIcon'), label = document.getElementById('themeLabel');
    function apply(t){
      if(t==='light'||t==='dark'){root.setAttribute('data-theme',t);}
      else{root.removeAttribute('data-theme');}
      var dark = t==='dark' || (t!=='light' && window.matchMedia('(prefers-color-scheme:dark)').matches);
      icon.textContent = dark ? '☀' : '☾';
      label.textContent = dark ? 'Claro' : 'Oscuro';
    }
    var saved;
    try { saved = localStorage.getItem('keytill-manual-theme'); } catch(e){}
    apply(saved || 'system');
    btn.addEventListener('click', function () {
      var cur = root.getAttribute('data-theme');
      var isDark = cur==='dark' || (!cur && window.matchMedia('(prefers-color-scheme:dark)').matches);
      var next = isDark ? 'light' : 'dark';
      apply(next);
      try { localStorage.setItem('keytill-manual-theme', next); } catch(e){}
    });
  })();
</script>
</body>
</html>
