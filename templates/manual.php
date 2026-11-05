<?php
/**
 * Manual de operatoria de mostrador — página autocontenida (sin theme).
 * La sirve KEYTILL_Manual::maybe_render() en `?keytill_manual=1`. Contenido
 * estático (HTML+CSS+un poco de JS para el tema claro/oscuro); no recibe datos.
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

// Marca del COMERCIO (configurable en Ajustes; por defecto el nombre del sitio).
$keytill_brand = KEYTILL_Settings::business()['name'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual de Mostrador · <?php echo esc_html( $keytill_brand ); ?></title>
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
        <span class="logo"><?php echo esc_html( $keytill_brand ); ?></span>
        <span class="kicker">POS &middot; Counter manual</span>
      </div>
      <button class="theme-btn" id="themeToggle" type="button" aria-label="Switch theme">
        <span id="themeIcon">&#9680;</span><span id="themeLabel">Theme</span>
      </button>
    </div>
    <h1>How to run the register</h1>
    <p class="lede">Everything you do from the counter, step by step: open the register, sell, take payment, refund, exchange, close the shift and manage the catalog. Keyboard-driven — every function key fires an action.</p>

    <div class="legend" aria-label="Function keys">
      <span class="legend__item"><kbd class="key key--brand">F2</kbd><span class="legend__lbl">Take payment</span></span>
      <span class="legend__item"><kbd class="key">F4</kbd><span class="legend__lbl">Customer</span></span>
      <span class="legend__item"><kbd class="key">F7</kbd><span class="legend__lbl">Refund</span></span>
      <span class="legend__item"><kbd class="key">F8</kbd><span class="legend__lbl">Discount</span></span>
      <span class="legend__item"><kbd class="key">F9</kbd><span class="legend__lbl">Close register</span></span>
      <span class="legend__item"><kbd class="key">F10</kbd><span class="legend__lbl">Withdrawal</span></span>
      <span class="legend__item"><kbd class="key">ESC</kbd><span class="legend__lbl">Cancel</span></span>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="shell">
    <nav class="toc" aria-label="Contents">
      <div class="toc__group">
        <p class="toc__h">Register and sale</p>
        <a href="#before"><span class="num">00</span>Before you start</a>
        <a href="#open"><span class="num">01</span>Open the register</a>
        <a href="#sell"><span class="num">02</span>Sell</a>
        <a href="#pay"><span class="num">03</span>Take payment</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Customer and adjustments</p>
        <a href="#customer"><span class="num">04</span>Assign a customer</a>
        <a href="#discount"><span class="num">05</span>Discount</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Refunds and credit</p>
        <a href="#refund"><span class="num">06</span>Refunds and exchanges</a>
        <a href="#credit"><span class="num">07</span>Credit and gift cards</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">End of shift</p>
        <a href="#withdrawal"><span class="num">08</span>Cash withdrawal</a>
        <a href="#close"><span class="num">09</span>Close the register</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Console</p>
        <a href="#orders"><span class="num">10</span>Orders</a>
        <a href="#products"><span class="num">11</span>Products</a>
        <a href="#customers"><span class="num">12</span>Customers</a>
        <a href="#cashbank"><span class="num">13</span>Register / Bank</a>
      </div>
      <div class="toc__group">
        <p class="toc__h">Reference</p>
        <a href="#keys"><span class="num">&#9733;</span>Keys and notices</a>
      </div>
    </nav>

    <main>
      <section class="op" id="before">
        <div class="op__head"><span class="op__no">00</span><h2 class="op__title">Before you start</h2></div>
        <p class="op__when">Three things worth knowing before you touch a key.</p>

        <p class="section-band">The command field</p>
        <p style="color:var(--ink-soft);margin:0">The register has one large box that is <strong>always focused</strong>: that is where you scan or search. It works with a barcode reader — the <strong>SKU is the code</strong>. You can also type a product name and pick it with the arrow keys.</p>
        <ul class="plain">
          <li>Scan or type, then press <kbd class="key">ENTER</kbd> to add it to the ticket.</li>
          <li>For several units at once: type <code>N*</code> before the code (e.g. <code>3*</code> then scan = 3 units).</li>
          <li>A product with sizes or colors has <strong>one code per variation</strong>: each one is scanned and draws down its own stock.</li>
        </ul>

        <p class="section-band">Who can do what</p>
        <div class="tablewrap"><table>
          <thead><tr><th>Role</th><th>Can</th></tr></thead>
          <tbody>
            <tr><td><span class="pill pill--role">Cashier</span></td><td>Sell, take payment, assign and create customers, process refunds, record withdrawals, close the register, and <strong>view</strong> the console tabs.</td></tr>
            <tr><td><span class="pill pill--role">Manager / Admin</span></td><td>Everything a cashier can, plus <strong>edit products</strong>: price, stock, enable/disable and create new ones.</td></tr>
          </tbody>
        </table></div>

        <div class="note note--warn"><span class="note__tag">Requirement</span>
          <p>You cannot sell with the register closed. The first thing every shift is to <a href="#open">open the register</a> with its opening float. A current version of Chrome, Edge, Firefox or Safari is required; the camera scanner also needs the site served over <strong>HTTPS</strong>.</p>
        </div>
      </section>

      <section class="op" id="open">
        <div class="op__head"><span class="op__no">01</span><h2 class="op__title">Open the register</h2></div>
        <p class="op__when">At the start of the shift. Records the cash the drawer starts with (the opening float) so the count adds up at closing.</p>
        <ol class="steps">
          <li>When you sign in with no register open, the <strong>Open register</strong> screen appears.</li>
          <li>Type the cash <strong>opening float</strong> (what is in the drawer to give change).</li>
          <li>Press <kbd class="key key--brand">ENTER</kbd>. The register is open and you can sell.</li>
        </ol>
        <div class="note note--info"><span class="note__tag">One register at a time</span>
          <p>There is one register per cashier at a time. If one is already open, the system will not open another: close the previous one first.</p>
        </div>
      </section>

      <section class="op" id="sell">
        <div class="op__head"><span class="op__no">02</span><h2 class="op__title">Sell: build the ticket</h2></div>
        <p class="op__when">Load what the customer is buying before taking payment.</p>
        <ol class="steps">
          <li>Scan the product (or type the name and pick it with <kbd class="key">↑</kbd> <kbd class="key">↓</kbd> + <kbd class="key">ENTER</kbd>). It is added to the ticket.</li>
          <li>To select a ticket line use <kbd class="key">↑</kbd> <kbd class="key">↓</kbd>; to change the quantity, <kbd class="key">+</kbd> <kbd class="key">−</kbd>.</li>
          <li>To remove the selected line: <kbd class="key">DEL</kbd>.</li>
          <li>When the ticket is complete, take payment with <kbd class="key key--brand">F2</kbd>.</li>
        </ol>
        <div class="note"><span class="note__tag">Note</span>
          <p>The total is always on the right. The stock shown is a reference; stock is actually drawn down when you take payment.</p>
        </div>
      </section>

      <section class="op" id="pay">
        <div class="op__head"><span class="op__no">03</span><h2 class="op__title">Take payment</h2><div class="op__keys"><kbd class="key key--brand key--lg">F2</kbd></div></div>
        <p class="op__when">Pick the payment method, take the money and (if needed) work out the change.</p>
        <ol class="steps">
          <li><kbd class="key key--brand">F2</kbd> opens payment. Pick the method by number — <kbd class="key">1</kbd> is always <strong>Cash</strong>, and <kbd class="key">2</kbd> onward are the methods you configured in settings (card, bank transfer, a wallet app, whatever you use).
            <ul class="plain" style="margin-top:10px">
              <li><kbd class="key">C</kbd> Store credit (redeem a code — see <a href="#credit">§07</a>).</li>
            </ul>
          </li>
          <li>For <strong>cash</strong>, type what you received and the system shows the <strong>change</strong>.</li>
          <li>Confirm with <kbd class="key key--brand">ENTER</kbd>. The sale is recorded and stock is drawn down.</li>
          <li>On the sale summary: <kbd class="key">P</kbd> prints the receipt &nbsp;·&nbsp; <kbd class="key">C</kbd> prints the <strong>gift receipt</strong> &nbsp;·&nbsp; <kbd class="key">ENTER</kbd> continues. The summary <strong>does not close</strong> when you print, so you can take both.</li>
        </ol>
        <div class="note note--info"><span class="note__tag">Gift receipt</span>
          <p>The same receipt but <strong>without prices</strong>, to put in a gift. It carries a <strong>barcode</strong>: when the item comes back for an exchange, go to <strong>Refund</strong> (<a href="#refund">§06</a>) and <strong>scan it</strong> — the sale loads straight away. It can be reprinted later from <strong>Orders</strong>.</p>
        </div>
        <div class="note note--info"><span class="note__tag">Split payment</span>
          <p>Part of a sale can be paid with a <strong>store credit</strong> (key <kbd class="key">C</kbd>) and the rest with another method. The system works out how much the credit covers and how much is still owed.</p>
        </div>
        <div class="note note--warn"><span class="note__tag">Not a tax invoice</span>
          <p>The receipt the POS prints is an <strong>internal, non-fiscal</strong> document (“not valid as an invoice”). If your country requires a tax invoice, issue it separately from your accounting or invoicing system.</p>
        </div>
      </section>

      <section class="op" id="customer">
        <div class="op__head"><span class="op__no">04</span><h2 class="op__title">Assign a customer</h2><div class="op__keys"><kbd class="key key--lg">F4</kbd></div></div>
        <p class="op__when">Optional. Use it to record who bought (and their tax details). A sale can stay anonymous.</p>
        <ol class="steps">
          <li><kbd class="key">F4</kbd> opens the search. Type a <strong>name, email or tax ID</strong>.</li>
          <li>Pick from the list with <kbd class="key">↑</kbd> <kbd class="key">↓</kbd> and assign with <kbd class="key">ENTER</kbd>.</li>
          <li>If there is no match, press <kbd class="key">TAB</kbd> to <strong>create one</strong>: name, plus an email or tax ID (at least one). <kbd class="key">ENTER</kbd> saves.</li>
          <li>To remove the assigned customer: <kbd class="key">DEL</kbd> with the search box empty.</li>
        </ol>
        <div class="note note--ok"><span class="note__tag">No emails</span>
          <p>POS customers are the same customers as your online store, but <strong>the POS never sends email</strong> — not when creating a customer, not when closing a sale. Email stays with online orders.</p>
        </div>
        <div class="note"><span class="note__tag">No duplicates</span>
          <p>If the email or tax ID already exists, the system will not create a second customer: it offers you the existing one to select.</p>
        </div>
        <div class="note"><span class="note__tag">Tax status field</span>
          <p>Some countries need a tax status on the customer record (VAT status, tax category). It is <strong>off by default</strong>; turn it on in <strong>Keytill → Settings</strong> if you need it.</p>
        </div>
      </section>

      <section class="op" id="discount">
        <div class="op__head"><span class="op__no">05</span><h2 class="op__title">Apply a discount</h2><div class="op__keys"><kbd class="key key--lg">F8</kbd></div></div>
        <p class="op__when">A discount on the whole ticket, or on a single item.</p>
        <ol class="steps">
          <li><kbd class="key">F8</kbd> opens the discount. Choose what it applies to: <kbd class="key">1</kbd> the whole sale, <kbd class="key">2</kbd> a single item.</li>
          <li>Type a <strong>percentage</strong> or a <strong>fixed amount</strong> — <kbd class="key">TAB</kbd> switches between the two. The new total is previewed.</li>
          <li><kbd class="key key--brand">ENTER</kbd> applies; <kbd class="key">ESC</kbd> cancels.</li>
        </ol>
        <div class="note"><span class="note__tag">Note</span>
          <p>A whole-sale discount and per-item discounts do not stack: applying one clears the other, and the status line tells you so.</p>
        </div>
      </section>

      <section class="op" id="refund">
        <div class="op__head"><span class="op__no">06</span><h2 class="op__title">Refunds and exchanges</h2><div class="op__keys"><kbd class="key key--lg">F7</kbd></div></div>
        <p class="op__when">Refund items from a sale (made at the POS <em>or</em> online), putting the stock back, and handle exchanges. An exchange is settled as a <strong>refund plus a new sale</strong>: the original order is never edited or deleted.</p>

        <p class="section-band">Refund</p>
        <ol class="steps">
          <li>Press <kbd class="key">F7</kbd>, enter the <strong>sale number</strong> and <kbd class="key">ENTER</kbd> to find it. You can also scan the gift receipt barcode.</li>
          <li>Pick the line with <kbd class="key">↑</kbd> <kbd class="key">↓</kbd> and the quantity to refund with <kbd class="key">+</kbd> <kbd class="key">−</kbd>.</li>
          <li>Pick the <strong>refund method</strong>: <kbd class="key">C</kbd> store credit &nbsp;·&nbsp; <kbd class="key">E</kbd> cash from the drawer &nbsp;·&nbsp; <kbd class="key">T</kbd> bank transfer (refunded by transfer, so it <strong>comes off the digital register</strong>, not the drawer).</li>
          <li><kbd class="key key--brand">ENTER</kbd> processes it. Stock goes back and, depending on the method, a credit code is issued or the drawer is drawn down.</li>
        </ol>

        <p class="section-band">Handling an exchange</p>
        <p style="color:var(--ink-soft);margin:0">The clean way is to <strong>refund to credit</strong> and spend that credit on the new sale. Both halves are then accounted for and the register still balances.</p>

        <div class="example">
          <p class="example__h">Same product, different size · same price</p>
          <div class="flow">
            <span class="step"><kbd class="key">F7</kbd> refund size M → credit</span>
            <span class="arr">→</span>
            <span class="step"><kbd class="key">F2</kbd> sell size L, redeem credit <kbd class="key">C</kbd></span>
            <span class="arr">→</span>
            <span class="zero">Total 0 · drawer untouched</span>
          </div>
        </div>

        <p style="color:var(--ink-soft);margin:18px 0 0">When the new product <strong>costs something different</strong>:</p>
        <div class="tablewrap"><table>
          <thead><tr><th>Difference</th><th>How to handle it</th></tr></thead>
          <tbody>
            <tr><td><span class="pill pill--up">New one costs more</span></td><td>The credit covers part of it and you <strong>charge the rest</strong> by any method. Payment works out the shortfall and the change on its own.</td></tr>
            <tr><td><span class="pill pill--down">New one costs less</span></td><td><strong>Option A —</strong> the leftover <strong>stays on the credit code</strong> and the customer keeps it for another purchase.<br><strong>Option B —</strong> to hand back the difference <em>in cash</em>, make the original refund in <strong>cash</strong> (not credit) and charge the new product separately.</td></tr>
          </tbody>
        </table></div>

        <div class="note note--warn"><span class="note__tag">Important · before refunding</span>
          <p>There is no way to cash out the leftover balance on a credit. So <strong>ask first</strong>: <em>are they swapping it for something, or do they want the money?</em> — and pick the refund method accordingly (credit if they are buying something else, cash if they want the difference).</p>
        </div>
        <div class="note"><span class="note__tag">Why the order is not “edited”</span>
          <p>Changing a sale that has already been paid would break both the register count and stock. A refund is the correct route: it leaves a record, puts stock back and moves the money in an accounted way.</p>
        </div>
      </section>

      <section class="op" id="credit">
        <div class="op__head"><span class="op__no">07</span><h2 class="op__title">Store credit and gift cards</h2></div>
        <p class="op__when">Store credit is a <strong>bearer</strong> instrument: a code like <code>KT-4K7P-9QX2</code> with a balance on it. It is issued on a refund, or sold as a gift card.</p>

        <p class="section-band">Redeem a credit</p>
        <ul class="plain">
          <li>At payment (<kbd class="key">F2</kbd>) press <kbd class="key">C</kbd> and enter the code. The balance is validated.</li>
          <li>It covers the sale <strong>fully or partly</strong>: if it falls short, the rest is paid by another method.</li>
          <li>Any unused balance <strong>stays on the same code</strong> for next time.</li>
        </ul>

        <p class="section-band">Sell a gift card</p>
        <ol class="steps">
          <li>Scan or search the <strong>gift card</strong> product and add it to the ticket.</li>
          <li>Pick a <strong>fixed denomination</strong> or type an <strong>open amount</strong>.</li>
          <li>Take payment as usual with <kbd class="key key--brand">F2</kbd>. On confirmation the system <strong>generates the code</strong> and shows it so you can hand it over (it is also on the receipt).</li>
        </ol>
        <div class="note note--info"><span class="note__tag">Validity</span>
          <p>Credits expire (30 days by default; <code>0</code> means never). The expiry date is shown when you look up the code. Denominations, the open-amount limit and the default validity are all set in <strong>Keytill → Settings</strong>, and credits are administered in the dashboard under <strong>Credits (POS)</strong>.</p>
        </div>
      </section>

      <section class="op" id="withdrawal">
        <div class="op__head"><span class="op__no">08</span><h2 class="op__title">Cash withdrawal</h2><div class="op__keys"><kbd class="key key--lg">F10</kbd></div></div>
        <p class="op__when">Take cash out of the drawer during the shift (paying a supplier, a bank deposit, and so on). It is recorded so the count still adds up.</p>
        <ol class="steps">
          <li>Press <kbd class="key">F10</kbd>, type the <strong>amount</strong> and the <strong>reason</strong> (<kbd class="key">TAB</kbd> between fields).</li>
          <li><kbd class="key key--brand">ENTER</kbd> records the withdrawal. The expected cash at closing adjusts on its own.</li>
        </ol>
        <div class="note"><span class="note__tag"><span class="pill pill--role">Manager / Admin</span> Where it comes from</span>
          <p>With management permission the withdrawal lets you choose the <strong>source</strong>: the <strong>cash register</strong> (money in the drawer) or the <strong>digital register</strong> (money leaving the bank account, e.g. a transfer to a supplier). A plain cashier can only withdraw cash. See <a href="#cashbank">§13</a>.</p>
        </div>
      </section>

      <section class="op" id="close">
        <div class="op__head"><span class="op__no">09</span><h2 class="op__title">Close the register (cash count)</h2><div class="op__keys"><kbd class="key key--lg">F9</kbd></div></div>
        <p class="op__when">At the end of the shift. Compares what the system expects in the drawer against what you physically count.</p>
        <ol class="steps">
          <li><kbd class="key">F9</kbd> opens the count. It shows the <strong>breakdown by method</strong> and the <strong>expected cash</strong>.</li>
          <li>Count the drawer and type the <strong>counted amount</strong>. The <strong>difference</strong> is worked out (over / short).</li>
          <li><kbd class="key key--brand">ENTER</kbd> closes the register and saves the shift summary.</li>
        </ol>
        <div class="note"><span class="note__tag">How the expected amount is worked out</span>
          <p><span class="money">Opening float</span> + <span class="money">cash sales</span> − <span class="money">withdrawals</span> − <span class="money">cash refunds</span>. Every non-cash method and store credit are listed separately, since they are not cash in the drawer.</p>
        </div>
        <div class="note note--info"><span class="note__tag">Who closes</span>
          <p>Only a <strong>manager or administrator</strong> can close the register. Finish or cancel the sale in progress first.</p>
        </div>
      </section>

      <section class="op" id="orders">
        <p class="section-band">The console · manage without leaving the POS</p>
        <div class="op__head"><span class="op__no">10</span><h2 class="op__title">Orders</h2></div>
        <p class="op__when">Find and review any order — from the POS and from the web — without going into the dashboard.</p>
        <ol class="steps">
          <li>Go to the <strong>Orders</strong> tab (top).</li>
          <li>Search by <strong>order number</strong> or <strong>customer name</strong>; filter by date, source, method, status or cashier.</li>
          <li>Open an order to see the detail. From there: <strong>Reprint</strong> the receipt, print the <strong>gift receipt</strong>, or <strong>Refund</strong> (opens the refund flow from <a href="#refund">§06</a>).</li>
          <li><strong>Export CSV</strong> downloads everything matching your filters, not just the page on screen.</li>
        </ol>
      </section>

      <section class="op" id="products">
        <div class="op__head"><span class="op__no">11</span><h2 class="op__title">Products</h2></div>
        <p class="op__when">Browse the catalog and, with management permission, make quick edits. A cashier sees it <strong>read only</strong>.</p>
        <ol class="steps">
          <li>Search by <strong>name, SKU or barcode</strong>; filter by category, status or stock.</li>
          <li><span class="pill pill--role">Manager / Admin</span> — edit <strong>price</strong> and <strong>stock</strong> right in the row (saved when you leave the field or press <kbd class="key">ENTER</kbd>), and switch a product on or off with the toggle.</li>
          <li>A product with variations (size, color) <strong>expands</strong> so you can edit the price and stock of each one.</li>
          <li><strong>New product</strong> creates one from the counter: name, SKU, price, stock, category, description and images. For a <strong>variable</strong> product, pick the attributes and their values and let it <strong>generate the variations</strong>.</li>
        </ol>
        <div class="note"><span class="note__tag">Labels</span>
          <p>Select products with a barcode and use <strong>Generate labels</strong> to print a sheet of 3.5 × 3.5 cm labels. Which variation attributes appear on the label is set in <strong>Keytill → Settings</strong>.</p>
        </div>
        <div class="note"><span class="note__tag">Stock by location</span>
          <p>If you defined locations, stock is tracked per location and the native WooCommerce stock is their sum. Pick a location on a product to place <strong>all</strong> of its stock there, or distribute it by hand. <strong>Move stock</strong> transfers units between two locations.</p>
        </div>
      </section>

      <section class="op" id="customers">
        <div class="op__head"><span class="op__no">12</span><h2 class="op__title">Customers</h2></div>
        <p class="op__when">Manage the store's customers from the counter.</p>
        <ol class="steps">
          <li>Search by <strong>name, email or tax ID</strong>.</li>
          <li>Open a customer to see their <strong>statistics</strong> (orders, total spent) and their <strong>purchase history</strong>, with reprinting of POS sales.</li>
          <li><strong>Edit</strong> their details or create a new one. Same as during a sale: no duplicates and <strong>no email is sent</strong>.</li>
        </ol>
      </section>

      <section class="op" id="cashbank">
        <div class="op__head"><span class="op__no">13</span><h2 class="op__title">Register / Bank</h2><div class="op__keys"><span class="pill pill--role">Manager / Admin</span></div></div>
        <p class="op__when">A management-only tab showing <strong>both registers</strong> with their balance to date: the <strong>cash register</strong> (money in the drawer) and the <strong>digital register</strong> (the single bank account that receives transfers, card payments and every other non-cash method). A plain cashier does not see it.</p>

        <p class="section-band">The two registers</p>
        <div class="tablewrap"><table>
          <thead><tr><th>Register</th><th>What it records</th><th>Opening</th></tr></thead>
          <tbody>
            <tr><td><strong>Cash</strong></td><td>Money in the drawer. It is per cashier — each one opens their own.</td><td>Opened by counting the <strong>opening float</strong> (see <a href="#open">§01</a>).</td></tr>
            <tr><td><strong>Digital</strong></td><td>The bank account. There is <strong>a single global one</strong>, shared. It adds up everything taken by non-cash methods.</td><td>The balance <strong>carries over</strong>: the opening equals the balance at the last close. The very first time you enter it by hand.</td></tr>
          </tbody>
        </table></div>

        <p class="section-band">Opening and closing the digital register</p>
        <ol class="steps">
          <li>In the <strong>Register / Bank</strong> tab, use <strong>Open digital register</strong>. The <strong>first time</strong> it asks for the <strong>opening balance</strong> (what is in the account today); after that it opens straight away with the balance from the last close.</li>
          <li>While it is open, every <strong>non-cash payment</strong> adds to it on its own, and you can record <strong>deposits and withdrawals</strong> by hand.</li>
          <li><strong>Closing</strong> draws the line: the final balance becomes the opening of the next period. You <strong>do not have to close it daily</strong> — leaving it open is fine; it is an administrative cut-off.</li>
        </ol>
        <div class="note"><span class="note__tag">Nothing is lost while it is closed</span>
          <p>Even with the digital register closed, transfer sales <strong>are still recorded</strong> and show up in the next period when you reopen it. When you open your cash register, if you are a manager or admin and the digital one is closed, the POS offers to open it.</p>
        </div>

        <p class="section-band">Deposits and withdrawals (with a source)</p>
        <ol class="steps">
          <li>Each register has <strong>Deposit</strong> and <strong>Withdrawal</strong> buttons: enter an <strong>amount</strong> and a <strong>reason</strong> (e.g. “supplier payment”, “owner contribution”).</li>
          <li>A <strong>withdrawal from the digital register</strong> is money leaving the account (e.g. you transfer it to a supplier). A <strong>deposit</strong> is money coming in that is not a sale.</li>
          <li>The quick withdrawal from the <strong>Sale</strong> screen (<kbd class="key">F10</kbd>) also lets management pick the <strong>source</strong> — cash or digital.</li>
        </ol>

        <p class="section-band">Reconciliation · transfers</p>
        <p class="op__when" style="margin:0 0 10px">To check that a transfer <strong>actually arrived</strong> in the bank, and to catch forged payment screenshots.</p>
        <ol class="steps">
          <li>Below the digital register you get the <strong>transfers for the period</strong> (sale, customer, amount).</li>
          <li>Check them against your online banking and mark each one: <strong>✓ Verified</strong> (it arrived) or <strong>✕ Not received</strong>. Tapping again returns it to <em>pending</em>.</li>
        </ol>

        <p class="section-band">Movement log</p>
        <ol class="steps">
          <li><strong>Movement log</strong> shows every movement across periods, not just today: date, movement (in or out), type (sale, deposit, withdrawal, refund), source (local or web), account, amount and detail.</li>
          <li>Filter by whatever you need and <strong>export to CSV</strong> — the export covers everything matching the filter, not just the page on screen.</li>
        </ol>

        <div class="note"><span class="note__tag">History · where to find the closes</span>
          <p>In the WordPress dashboard under the <strong>Keytill</strong> menu: <strong>Registers (POS)</strong> for cash and <strong>Digital register</strong> for the account. Each period is listed with its totals; clicking the <strong>opening date</strong> opens the <strong>detail</strong> — movements with their reason, the breakdown by method, and transfers with their verification state.</p>
        </div>
      </section>

      <section class="op ref" id="keys">
        <p class="section-band">Quick reference</p>
        <div class="op__head"><span class="op__no">&#9733;</span><h2 class="op__title">Keys and notices</h2></div>
        <div class="tablewrap"><table>
          <thead><tr><th>Key</th><th>What it does</th><th>Where</th></tr></thead>
          <tbody>
            <tr><td><kbd class="key key--brand">F2</kbd></td><td>Take payment</td><td>Sale</td></tr>
            <tr><td><kbd class="key">F4</kbd></td><td>Assign / create customer</td><td>Sale</td></tr>
            <tr><td><kbd class="key">F7</kbd></td><td>Refund / exchange</td><td>Sale</td></tr>
            <tr><td><kbd class="key">F8</kbd></td><td>Discount (% or amount)</td><td>Sale</td></tr>
            <tr><td><kbd class="key">F9</kbd></td><td>Close register / cash count</td><td>Sale</td></tr>
            <tr><td><kbd class="key">F10</kbd></td><td>Withdrawal</td><td>Sale</td></tr>
            <tr><td><kbd class="key">ENTER</kbd></td><td>Add / confirm</td><td>Everywhere</td></tr>
            <tr><td><kbd class="key">↑</kbd> <kbd class="key">↓</kbd></td><td>Select line / result</td><td>Sale</td></tr>
            <tr><td><kbd class="key">+</kbd> <kbd class="key">−</kbd></td><td>Line quantity</td><td>Sale</td></tr>
            <tr><td><kbd class="key">DEL</kbd></td><td>Remove line / customer</td><td>Sale</td></tr>
            <tr><td><kbd class="key">P</kbd></td><td>Print receipt</td><td>After payment</td></tr>
            <tr><td><kbd class="key">C</kbd></td><td>Redeem credit · refund to credit</td><td>Payment · Refund</td></tr>
            <tr><td><kbd class="key">E</kbd></td><td>Refund in cash</td><td>Refund</td></tr>
            <tr><td><kbd class="key">T</kbd></td><td>Refund by bank transfer</td><td>Refund</td></tr>
            <tr><td><kbd class="key">1</kbd>…<kbd class="key">9</kbd></td><td>Payment method (1 is always Cash)</td><td>Payment</td></tr>
            <tr><td><kbd class="key">N</kbd><kbd class="key">*</kbd></td><td>N units of the next code</td><td>Command field</td></tr>
            <tr><td><kbd class="key">ESC</kbd></td><td>Cancel / close</td><td>Everywhere</td></tr>
          </tbody>
        </table></div>
      </section>
    </main>
  </div>
</div>

<footer>
  <div class="foot">
    <div class="foot__grid">
      <div><h4>Not a tax invoice</h4><p>The POS receipt is internal and <strong>non-fiscal</strong>. If your country requires an invoice, issue it separately.</p></div>
      <div><h4>No emails</h4><p>The POS <strong>never sends email</strong>: not when creating customers, not when closing sales. That belongs to the online store.</p></div>
      <div><h4>Browser</h4><p>Use a current browser in full screen. The register must be open to sell, and the camera scanner needs <strong>HTTPS</strong>.</p></div>
    </div>
    <p style="margin-top:24px;color:var(--ink-faint);font-family:var(--font-mono);font-size:12px;letter-spacing:.04em"><?php echo esc_html( $keytill_brand ); ?> &middot; Counter POS &middot; Operating manual</p>
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
      label.textContent = dark ? 'Light' : 'Dark';
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
