<?php
/**
 * Contenedor del POS (montado por el shortcode [keytill]).
 * Pantalla de venta en Alpine, portada del prototipo. Solo markup +
 * bindings; la lógica vive en assets/js/pos.js (Alpine.data('keytillApp')).
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

$keytill_user       = wp_get_current_user();
$keytill_can_manage = current_user_can( KEYTILL_CAP_MANAGE );
$keytill_logout_url = wp_logout_url( get_permalink() ); // tras salir, vuelve al login del POS
?>
<div class="keytill" id="keytill-app" x-data="keytillApp()" :class="{ 'is-cart-open': cartSheet }" x-cloak>

	<!-- ================= NAV GLOBAL (shell) ================= -->
	<nav class="keytill-nav">
		<span class="keytill-brand">KEYTILL</span>
		<div class="keytill-nav__tabs" role="tablist">
			<template x-for="t in $store.pos.tabs" :key="t.id">
				<button type="button" class="keytill-nav__tab"
					role="tab"
					:class="{ 'is-active': $store.pos.view === t.id }"
					:aria-selected="$store.pos.view === t.id"
					@click="$store.pos.go(t.id)"
					x-text="t.label"></button>
			</template>
		</div>
		<span class="keytill-nav__cashier"><?php echo esc_html( $keytill_user->display_name ); ?> · <?php echo esc_html( KEYTILL_Settings::register_label() ); ?></span>
		<a class="keytill-nav__exit" href="<?php echo esc_url( KEYTILL_Manual::url() ); ?>" target="_blank" rel="noopener" tabindex="-1">
			<?php esc_html_e( 'Manual', 'keytill' ); ?> ↗
		</a>
		<?php if ( $keytill_can_manage ) : ?>
			<a class="keytill-nav__exit" href="<?php echo esc_url( admin_url() ); ?>" tabindex="-1">
				<?php esc_html_e( 'Panel', 'keytill' ); ?> ↗
			</a>
		<?php endif; ?>
		<a class="keytill-nav__exit" href="<?php echo esc_url( $keytill_logout_url ); ?>" tabindex="-1">
			<?php esc_html_e( 'Cerrar sesión', 'keytill' ); ?>
		</a>
	</nav>

	<!-- ================= VISTA: VENTA ================= -->
	<div class="keytill-view keytill-view--venta" x-show="$store.pos.view === 'venta'">

	<!-- ================= COLUMNA IZQUIERDA ================= -->
	<div class="keytill-col keytill-col--main">

		<!-- Campo de comando -->
		<div class="keytill-card cmd-card">
			<div class="cmd-card__head">
				<span class="cmd-card__label">ESCANEÁ O BUSCÁ</span>
				<span class="cmd-card__prefix" x-show="qtyPrefix > 0" x-cloak>
					×<span x-text="qtyPrefix"></span> en espera
				</span>
				<span class="cmd-card__hint">N* + código = N unidades</span>
			</div>
			<input
				type="text"
				x-ref="cmd"
				:value="cmd"
				@input="onInput($event)"
				@keydown.enter="if ($store.pos.touch) { $event.preventDefault(); submitCommand(); }"
				class="cmd-input"
				placeholder="código de barras / nombre del producto…"
				autocomplete="off"
				spellcheck="false"
				aria-label="<?php esc_attr_e( 'Campo de comando: escaneá o buscá', 'keytill' ); ?>"
			/>
			<!--
				Campo de escaneo HID latente (lector USB/Bluetooth tipo "keyboard wedge").
				inputmode="none" ⇒ recibe el escaneo pero NO abre el teclado en pantalla.
				Solo se monta en táctil y no se auto-enfoca (soporte de lector queda
				disponible sin invertir en su UX; ver STATUS). Alimenta el mismo buffer
				de comando que el campo de búsqueda visible.
			-->
			<template x-if="touch">
				<input
					type="text"
					x-ref="scan"
					inputmode="none"
					:value="cmd"
					@input="onInput($event)"
					@keydown.enter.prevent="submitCommand()"
					class="keytill-scan"
					tabindex="-1"
					aria-hidden="true"
					autocomplete="off"
					spellcheck="false"
				/>
			</template>
		</div>

		<!-- Resultados de búsqueda -->
		<div class="keytill-card results" x-show="results().length > 0" x-cloak>
			<div class="results__head">
				<span>RESULTADOS (<span x-text="results().length"></span>)</span>
				<span>↑ ↓ para elegir · ENTER agrega</span>
			</div>
			<template x-for="(r, i) in results()" :key="r.id">
				<div class="results__row" :class="{ 'is-sel': i === resIdx }" @click="addProduct(r)" role="button" tabindex="-1">
					<span class="mono muted" x-text="r.sku"></span>
					<span class="results__name">
						<span x-text="r.name"></span>
						<template x-if="r.variation"><span class="variation-tag" x-text="r.variation"></span></template>
						<template x-if="r.badge"><span class="stock-badge" :class="r.badge.cls" x-text="r.badge.label"></span></template>
						<template x-if="r.avail">
							<span class="branch-line">
								<template x-for="(seg, si) in r.avail" :key="si">
									<span :class="seg.cls"><template x-if="si > 0"><span class="bl-sep"> · </span></template><span x-text="seg.txt"></span></span>
								</template>
							</span>
						</template>
						<template x-if="!r.avail && r.branch"><span class="branch-line" x-text="branchLine(r)"></span></template>
					</span>
					<span class="mono muted results__cat" x-text="r.category"></span>
					<span class="mono results__price" x-text="money(r.price)"></span>
				</div>
			</template>
		</div>

		<!-- Carrito -->
		<div class="keytill-card cart">
			<div class="cart__sheet-head">
				<span><?php esc_html_e( 'Carrito', 'keytill' ); ?> · <span class="mono" x-text="itemCount()"></span></span>
				<button type="button" class="cart__sheet-close" @click="closeCartSheet()" aria-label="<?php esc_attr_e( 'Cerrar', 'keytill' ); ?>">✕</button>
			</div>
			<div class="cart__head">
				<span>#</span>
				<span>DESCRIPCIÓN</span>
				<span class="ta-c">CANT</span>
				<span class="ta-r">P. UNIT</span>
				<span class="ta-r">SUBTOTAL</span>
			</div>
			<div class="cart__body">
				<template x-for="(l, i) in lines" :key="l.id">
					<div class="line" :class="{ 'is-sel': i === sel, 'is-flash': flashId === l.id }">
						<span class="mono muted line__n" x-text="String(i + 1).padStart(2, '0')"></span>
						<span class="line__desc">
							<span class="line__name">
								<span x-text="l.name"></span>
								<template x-if="l.variation"><span class="variation-tag" x-text="l.variation"></span></template>
							</span>
							<span class="line__meta">
								<span class="mono muted line__code" x-text="l.code"></span>
								<template x-if="l.badge"><span class="stock-badge" :class="l.badge.cls" x-text="l.badge.label"></span></template>
								<template x-if="l.disc > 0"><span class="line__disc" x-text="l.discType === 'fixed' ? ('− ' + money(l.disc)) : ('− ' + l.disc + '%')"></span></template>
								<template x-if="l.branch"><span class="branch-line" x-text="'Disponible en sucursal ' + l.branch"></span></template>
							</span>
						</span>
						<span class="mono line__qty" x-text="l.qty"></span>
						<span class="mono line__unit" x-text="money(l.price)"></span>
						<span class="mono line__sub">
							<template x-if="l.disc > 0"><span class="line__sub-orig" x-text="money(l.price * l.qty)"></span></template>
							<span x-text="money(lineNet(l))"></span>
						</span>
						<div class="line__ctrl" x-show="$store.pos.touch" x-cloak>
							<button type="button" class="line__btn line__btn--disc" @click.stop="openLineDiscount(i)" aria-label="<?php esc_attr_e( 'Descuento', 'keytill' ); ?>">%</button>
							<button type="button" class="line__btn" @click.stop="lineDec(i)" aria-label="<?php esc_attr_e( 'Restar', 'keytill' ); ?>">−</button>
							<span class="line__ctrl-qty mono" x-text="l.qty"></span>
							<button type="button" class="line__btn" @click.stop="lineInc(i)" aria-label="<?php esc_attr_e( 'Sumar', 'keytill' ); ?>">+</button>
							<button type="button" class="line__btn line__btn--rm" @click.stop="lineRemove(i)" aria-label="<?php esc_attr_e( 'Quitar', 'keytill' ); ?>">✕</button>
						</div>
					</div>
				</template>
				<div class="cart__empty" x-show="lines.length === 0" x-cloak>
					<span class="mono">CARRITO VACÍO</span>
					<span><?php esc_html_e( 'Escaneá un producto para empezar la venta', 'keytill' ); ?></span>
				</div>
			</div>
		</div>

		<!-- Leyenda de atajos -->
		<div class="keytill-card legend">
			<span class="legend__item"><kbd>ENTER</kbd> agregar / confirmar</span>
			<span class="legend__item"><kbd>↑ ↓</kbd> elegir línea</span>
			<span class="legend__item"><kbd>+ −</kbd> cantidad</span>
			<span class="legend__item"><kbd>SUPR</kbd> quitar línea</span>
			<span class="legend__item legend__item--strong"><kbd class="kbd-brand">F2</kbd> COBRAR</span>
			<span class="legend__item"><kbd>F4</kbd> cliente</span>
			<span class="legend__item"><kbd>F7</kbd> devolución</span>
			<span class="legend__item"><kbd>F8</kbd> descuento</span>
			<span class="legend__item" x-show="$store.pos.canManage"><kbd>F9</kbd> cerrar caja</span>
			<span class="legend__item"><kbd>F10</kbd> retiro</span>
			<span class="legend__item"><kbd>ESC</kbd> cancelar / cerrar</span>
		</div>
	</div>

	<!-- ================= COLUMNA DERECHA ================= -->
	<div class="keytill-col keytill-col--side">

		<!-- Totales -->
		<div class="keytill-card totals">
			<div class="totals__meta">
				<span><span x-text="itemCount()"></span> ítems · <span x-text="lines.length"></span> líneas</span>
				<span class="mono" x-text="money(subtotal())"></span>
			</div>
			<div class="totals__discount" x-show="discount > 0" x-cloak>
				<span>DESCUENTO <span x-text="discountType === 'fixed' ? money(discount) : (discount + '%')"></span></span>
				<span class="mono">− <span x-text="money(discountAmount())"></span></span>
			</div>
			<div class="totals__sep"></div>
			<span class="totals__label">TOTAL</span>
			<span class="totals__amount mono">
				<span class="cur">$</span> <span x-text="money(total()).replace('$ ', '')"></span>
			</span>
			<div class="totals__cta">
				<kbd class="kbd-brand">F2</kbd>
				<span><?php esc_html_e( 'para cobrar', 'keytill' ); ?></span>
			</div>
		</div>

		<!-- Cliente -->
		<div class="keytill-card customer">
			<div class="customer__head">
				<span>CLIENTE</span>
				<kbd>F4</kbd>
			</div>
			<span class="customer__name" x-text="customer ? customer.name || customer.doc : 'Consumidor final'"></span>
			<span class="mono muted customer__doc"
				x-text="customer ? [customer.doc, customer.iva].filter(Boolean).join(' · ') || 'sin datos fiscales' : ($store.pos.touch ? 'sin datos · tocá «Cliente»' : 'sin datos · F4 para cargar')"></span>
		</div>

		<!-- Estado -->
		<div class="keytill-card status" :class="'status--' + statusLevel">
			<span class="status__dot" :class="{ 'is-busy': busy }"></span>
			<span class="mono status__text" x-text="status"></span>
		</div>

		<!-- Caja abierta -->
		<div class="keytill-card caja" x-show="session" x-cloak>
			<div class="caja__head">
				<span>CAJA ABIERTA</span>
				<span class="mono" x-text="session ? '#' + session.register_session_id : ''"></span>
			</div>
			<div class="caja__row" x-show="session && session.branch_name" x-cloak>
				<span><?php esc_html_e( 'Sucursal', 'keytill' ); ?></span>
				<span class="mono" x-text="session ? session.branch_name : ''"></span>
			</div>
			<div class="caja__row">
				<span><?php esc_html_e( 'Fondo inicial', 'keytill' ); ?></span>
				<span class="mono" x-text="session ? money(session.opening_float) : ''"></span>
			</div>
			<div class="caja__row" x-show="session && session.cajero_name" x-cloak>
				<span><?php esc_html_e( 'Abierta por', 'keytill' ); ?></span>
				<span class="mono muted" x-text="session ? session.cajero_name : ''"></span>
			</div>
			<div class="caja__actions">
				<span x-show="$store.pos.canManage"><kbd>F9</kbd> <?php esc_html_e( 'cerrar', 'keytill' ); ?></span>
				<span><kbd>F10</kbd> <?php esc_html_e( 'retiro', 'keytill' ); ?></span>
			</div>
		</div>
	</div>

		<!-- Backdrop del bottom sheet del carrito (solo layouts apilados) -->
		<div class="keytill-sheet-backdrop" x-show="cartSheet" @click="closeCartSheet()" x-cloak></div>

		<!-- ================= BARRA DE ACCIONES TÁCTIL ================= -->
		<!-- Reemplaza las F-keys en móvil/tablet. Targets ≥44px. -->
		<div class="keytill-actionbar" x-show="$store.pos.touch" x-cloak>
			<div class="keytill-actionbar__row">
				<button type="button" class="keytill-abtn keytill-abtn--scan" @click="openScanner()">📷 <?php esc_html_e( 'Escanear', 'keytill' ); ?></button>
				<button type="button" class="keytill-abtn keytill-abtn--cart" @click="toggleCartSheet()">
					<span class="keytill-abtn__lbl"><?php esc_html_e( 'Carrito', 'keytill' ); ?></span>
					<span class="mono keytill-abtn__badge" x-text="itemCount()"></span>
				</button>
				<button type="button" class="keytill-abtn" @click="openCustomer()"><?php esc_html_e( 'Cliente', 'keytill' ); ?></button>
				<button type="button" class="keytill-abtn" @click="openDiscount()"><?php esc_html_e( 'Descuento', 'keytill' ); ?></button>
				<button type="button" class="keytill-abtn" @click="openReturn()"><?php esc_html_e( 'Devolución', 'keytill' ); ?></button>
				<button type="button" class="keytill-abtn" @click="openWithdraw()" x-show="session" x-cloak><?php esc_html_e( 'Retiro', 'keytill' ); ?></button>
				<button type="button" class="keytill-abtn" @click="openClose()" x-show="session && $store.pos.canManage" x-cloak><?php esc_html_e( 'Cerrar caja', 'keytill' ); ?></button>
			</div>
			<button type="button" class="keytill-abtn keytill-abtn--pay" @click="openPay()">
				<span><?php esc_html_e( 'Cobrar', 'keytill' ); ?></span>
				<span class="mono" x-text="money(total())"></span>
			</button>
		</div>

	</div><!-- /vista VENTA -->

	<!-- ================= VISTA: PEDIDOS ================= -->
	<div class="keytill-view keytill-orders" x-show="$store.pos.view === 'pedidos'" x-data="keytillOrders()" x-cloak>

		<div class="orders__bar">
			<input type="search" class="orders__search" placeholder="<?php esc_attr_e( 'Buscar por N° o cliente…', 'keytill' ); ?>"
				x-model="filters.search" @input="onSearch()" @keydown.enter="apply()" autocomplete="off" />
			<div class="orders__presets">
				<template x-for="p in [{k:'hoy',l:'Hoy'},{k:'7d',l:'7 días'},{k:'30d',l:'30 días'},{k:'todo',l:'Todo'}]" :key="p.k">
					<button type="button" class="orders__preset" :class="{ 'is-active': preset === p.k }" @click="setPreset(p.k)" x-text="p.l"></button>
				</template>
			</div>
			<select class="orders__filter" x-model="filters.origin" @change="apply()">
				<option value=""><?php esc_html_e( 'Origen: todos', 'keytill' ); ?></option>
				<option value="pos">POS</option>
				<option value="web"><?php esc_html_e( 'Web', 'keytill' ); ?></option>
			</select>
			<select class="orders__filter" x-model="filters.payment_method" @change="apply()">
				<option value=""><?php esc_html_e( 'Medio: todos', 'keytill' ); ?></option>
				<template x-for="m in meta.payment_methods" :key="m.id"><option :value="m.id" x-text="m.label"></option></template>
			</select>
			<select class="orders__filter" x-model="filters.status" @change="apply()">
				<option value=""><?php esc_html_e( 'Estado: todos', 'keytill' ); ?></option>
				<template x-for="s in meta.statuses" :key="s.id"><option :value="s.id" x-text="s.label"></option></template>
			</select>
			<select class="orders__filter" x-model="filters.cajero_id" @change="apply()">
				<option value=""><?php esc_html_e( 'Cajero: todos', 'keytill' ); ?></option>
				<template x-for="c in meta.cashiers" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
			</select>
			<span class="orders__spacer"></span>
			<label class="orders__daterange"><?php esc_html_e( 'Desde', 'keytill' ); ?>
				<input type="date" class="orders__filter" x-model="filters.date_from" @change="setCustomRange()" />
			</label>
			<label class="orders__daterange"><?php esc_html_e( 'Hasta', 'keytill' ); ?>
				<input type="date" class="orders__filter" x-model="filters.date_to" @change="setCustomRange()" />
			</label>
			<button type="button" class="orders__export" @click="exportCsv()" title="<?php esc_attr_e( 'Descargar CSV para Excel', 'keytill' ); ?>">⬇ <?php esc_html_e( 'Exportar CSV', 'keytill' ); ?></button>
		</div>

		<div class="prod__err" x-show="error" x-cloak x-text="error"></div>

		<div class="orders__list">
			<table class="orders__table">
				<thead>
					<tr>
						<th>N°</th><th><?php esc_html_e( 'Fecha', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Cliente', 'keytill' ); ?></th><th><?php esc_html_e( 'Cajero', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Medio', 'keytill' ); ?></th><th><?php esc_html_e( 'Origen', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'keytill' ); ?></th><th class="n"><?php esc_html_e( 'Total', 'keytill' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="o in items" :key="o.id">
						<tr class="orders__row" @click="open(o.id)">
							<td class="mono">#<span x-text="o.number"></span></td>
							<td class="mono" x-text="o.date"></td>
							<td x-text="o.customer || '—'"></td>
							<td class="mono muted" x-text="o.cashier || '—'"></td>
							<td x-text="o.payment_title"></td>
							<td><span class="origin-tag" :class="'is-' + o.origin" x-text="o.origin === 'pos' ? 'POS' : 'Web'"></span></td>
							<td><span class="status-tag" :class="'is-' + o.status" x-text="o.status_label"></span></td>
							<td class="n mono" x-text="o.total_f"></td>
						</tr>
					</template>
				</tbody>
			</table>
			<div class="orders__empty" x-show="!loading && !items.length"><?php esc_html_e( 'Sin pedidos.', 'keytill' ); ?></div>
			<div class="orders__empty" x-show="loading"><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>
		</div>

		<div class="orders__foot">
			<span class="mono muted"><span x-text="total"></span> <?php esc_html_e( 'pedidos', 'keytill' ); ?></span>
			<div class="orders__pager" x-show="pages > 1">
				<button type="button" @click="goPage(page - 1)" :disabled="page <= 1">‹</button>
				<span class="mono"><span x-text="page"></span> / <span x-text="pages"></span></span>
				<button type="button" @click="goPage(page + 1)" :disabled="page >= pages">›</button>
			</div>
		</div>

		<!-- Detalle (drawer) -->
		<div class="orders__drawer" x-show="selected || detailLoading" x-cloak @click.self="closeDetail()">
			<aside class="drawer">
				<div class="drawer__head">
					<div>
						<span class="drawer__num"><?php esc_html_e( 'Pedido', 'keytill' ); ?> #<span x-text="selected ? selected.number : ''"></span></span>
						<span class="mono muted" x-text="selected ? selected.date : ''"></span>
					</div>
					<button type="button" class="drawer__close" @click="closeDetail()" aria-label="Cerrar">✕</button>
				</div>

				<div class="drawer__loading" x-show="detailLoading"><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>

				<template x-if="selected">
					<div class="drawer__body">
						<div class="drawer__tags">
							<span class="origin-tag" :class="'is-' + selected.origin" x-text="selected.origin === 'pos' ? 'POS' : 'Web'"></span>
							<span class="status-tag" :class="'is-' + selected.status" x-text="selected.status_label"></span>
							<span class="mono muted" x-show="selected.cashier" x-text="'Cajero: ' + selected.cashier"></span>
							<span class="mono muted" x-show="selected.seller" x-text="'Vendedor: ' + selected.seller"></span>
						</div>

						<div class="drawer__cust" x-show="selected.customer.name || selected.customer.doc || selected.customer.email">
							<b x-text="selected.customer.name || '<?php esc_attr_e( 'Consumidor final', 'keytill' ); ?>'"></b>
							<span class="mono muted" x-text="[selected.customer.doc, selected.customer.iva, selected.customer.email].filter(Boolean).join(' · ')"></span>
						</div>

						<table class="drawer__lines">
							<template x-for="(l, i) in selected.lines" :key="i">
								<tr>
									<td class="c" x-text="l.qty"></td>
									<td><span x-text="l.name"></span><span class="drawer__attrs mono muted" x-show="l.attrs" x-text="l.attrs"></span></td>
									<td class="n mono" x-text="l.total_f"></td>
								</tr>
							</template>
						</table>

						<div class="drawer__totals">
							<template x-if="selected.discount > 0">
								<div class="drawer__row"><span><?php esc_html_e( 'Subtotal', 'keytill' ); ?></span><span class="mono" x-text="selected.subtotal_f"></span></div>
							</template>
							<template x-if="selected.discount > 0">
								<div class="drawer__row"><span><?php esc_html_e( 'Descuento', 'keytill' ); ?></span><span class="mono" x-text="'−' + selected.discount_f"></span></div>
							</template>
							<div class="drawer__row drawer__row--grand"><span><?php esc_html_e( 'Total', 'keytill' ); ?></span><span class="mono" x-text="selected.total_f"></span></div>
							<template x-if="selected.refunded > 0">
								<div class="drawer__row drawer__row--refund"><span><?php esc_html_e( 'Devuelto', 'keytill' ); ?></span><span class="mono" x-text="'−' + selected.refunded_f"></span></div>
							</template>
						</div>

						<div class="drawer__pay">
							<div class="drawer__row"><span><?php esc_html_e( 'Medio de pago', 'keytill' ); ?></span><span x-text="selected.payment_title"></span></div>
							<template x-if="selected.credit_used > 0">
								<div class="drawer__row"><span><?php esc_html_e( 'Crédito', 'keytill' ); ?><span x-show="selected.credit_code" x-text="' (' + selected.credit_code + ')'"></span></span><span class="mono" x-text="selected.credit_used_f"></span></div>
							</template>
							<template x-if="selected.tendered_f">
								<div class="drawer__row"><span><?php esc_html_e( 'Recibido', 'keytill' ); ?></span><span class="mono" x-text="selected.tendered_f"></span></div>
							</template>
							<template x-if="selected.change_f">
								<div class="drawer__row"><span><?php esc_html_e( 'Vuelto', 'keytill' ); ?></span><span class="mono" x-text="selected.change_f"></span></div>
							</template>
						</div>

						<template x-if="selected.giftcards.length">
							<div class="drawer__gc">
								<span class="mono muted"><?php esc_html_e( 'Gift cards emitidas', 'keytill' ); ?></span>
								<template x-for="(g, i) in selected.giftcards" :key="i">
									<span class="mono drawer__code" x-text="g.code"></span>
								</template>
							</div>
						</template>

						<div class="drawer__actions">
							<button type="button" class="drawer__btn" @click="reprint()"><?php esc_html_e( 'Reimprimir', 'keytill' ); ?></button>
							<button type="button" class="drawer__btn" x-show="selected.gift_url" x-cloak @click="reprintGift()"><?php esc_html_e( 'Ticket de cambio', 'keytill' ); ?></button>
							<button type="button" class="drawer__btn drawer__btn--warn" x-show="selected.refundable" @click="refund()"><?php esc_html_e( 'Devolver', 'keytill' ); ?></button>
						</div>
					</div>
				</template>
			</aside>
		</div>
	</div>

	<!-- ================= VISTA: PRODUCTOS ================= -->
	<div class="keytill-view keytill-products" x-show="$store.pos.view === 'productos'" x-data="keytillProducts()" x-cloak>

		<div class="prod__bar">
			<input type="search" class="prod__search" placeholder="<?php esc_attr_e( 'Buscar por nombre, SKU o código de barras…', 'keytill' ); ?>"
				x-model="filters.search" @input="onSearch()" @keydown.enter="apply()" autocomplete="off" />
			<select class="prod__filter" x-model="filters.category" @change="apply()">
				<option value=""><?php esc_html_e( 'Categoría: todas', 'keytill' ); ?></option>
				<template x-for="c in meta.categories" :key="c.id"><option :value="c.slug" x-text="c.name + ' (' + c.count + ')'"></option></template>
			</select>
			<select class="prod__filter" x-model="filters.status" @change="apply()">
				<option value=""><?php esc_html_e( 'Estado: todos', 'keytill' ); ?></option>
				<option value="active"><?php esc_html_e( 'Activos', 'keytill' ); ?></option>
				<option value="inactive"><?php esc_html_e( 'Inactivos', 'keytill' ); ?></option>
			</select>
			<select class="prod__filter" x-model="filters.stock" @change="apply()">
				<option value=""><?php esc_html_e( 'Stock: todos', 'keytill' ); ?></option>
				<option value="instock"><?php esc_html_e( 'En stock', 'keytill' ); ?></option>
				<option value="outofstock"><?php esc_html_e( 'Sin stock', 'keytill' ); ?></option>
			</select>
			<span class="prod__spacer"></span>
			<button type="button" class="prod__new" x-show="canManage" @click="openCreate()">+ <?php esc_html_e( 'Nuevo producto', 'keytill' ); ?></button>
		</div>

		<div class="prod__readonly mono muted" x-show="!canManage" x-cloak><?php esc_html_e( 'Solo lectura — la edición requiere permiso de gestión.', 'keytill' ); ?></div>
		<div class="prod__err" x-show="error" x-cloak x-text="error"></div>

		<!-- Escaneo: código de 12 díg. sin resultados → crear/agregar desde el código. -->
		<div class="prod__scan" x-show="canManage && isScanCode() && !loading && total === 0" x-cloak>
			<span class="mono"><?php esc_html_e( 'Código', 'keytill' ); ?> <b x-text="filters.search"></b> <?php esc_html_e( 'no encontrado.', 'keytill' ); ?></span>
			<button type="button" class="prod__new" :disabled="scanBusy" @click="scanFromCode()">
				<span x-show="!scanBusy"><?php esc_html_e( '＋ Crear / agregar desde el código', 'keytill' ); ?></span>
				<span x-show="scanBusy" x-cloak><?php esc_html_e( 'Procesando…', 'keytill' ); ?></span>
			</button>
		</div>

		<!-- Modo de selección: etiquetas (por defecto) o sucursal. Solo con gestión y
		     si hay sucursales cargadas. Nunca se ven dos columnas de checkboxes a la vez. -->
		<div class="prod__modebar" x-show="canManage && branches.length" x-cloak>
			<span class="mono muted"><?php esc_html_e( 'Seleccionar para:', 'keytill' ); ?></span>
			<div class="prod__seg">
				<button type="button" :class="{ 'is-on': selMode === 'labels' }" @click="setSelMode('labels')"><?php esc_html_e( 'Etiquetas', 'keytill' ); ?></button>
				<button type="button" :class="{ 'is-on': selMode === 'branch' }" @click="setSelMode('branch')"><?php esc_html_e( 'Sucursal', 'keytill' ); ?></button>
			</div>
		</div>

		<!-- Barra de selección para etiquetas en lote (modo etiquetas) -->
		<div class="prod__selbar" x-show="selMode === 'labels' && labelSelCount > 0" x-cloak>
			<span class="mono"><span x-text="labelSelCount"></span> <?php esc_html_e( 'seleccionado(s) para etiquetas', 'keytill' ); ?></span>
			<span class="prod__spacer"></span>
			<button type="button" class="prod__link" @click="clearLabelSel()"><?php esc_html_e( 'Limpiar', 'keytill' ); ?></button>
			<button type="button" class="prod__new" @click="openLabelsBatch()">🏷 <?php esc_html_e( 'Generar etiquetas', 'keytill' ); ?></button>
		</div>

		<!-- Barra de asignación de sucursal en lote (modo sucursal) -->
		<div class="prod__selbar" x-show="selMode === 'branch'" x-cloak>
			<span class="mono">
				<template x-if="branchAll"><span><?php esc_html_e( 'Todos', 'keytill' ); ?> (<span x-text="total"></span> <?php esc_html_e( 'del filtro', 'keytill' ); ?>)</span></template>
				<template x-if="!branchAll"><span><span x-text="branchSelCount"></span> <?php esc_html_e( 'seleccionado(s)', 'keytill' ); ?></span></template>
			</span>
			<button type="button" class="prod__link" x-show="!branchAll && total > 0" @click="selectAllBranchFilter()"><?php esc_html_e( 'Seleccionar todos', 'keytill' ); ?> (<span x-text="total"></span>)</button>
			<span class="prod__spacer"></span>
			<select class="prod__filter" x-model="branchTarget">
				<option value=""><?php esc_html_e( 'Elegí sucursal…', 'keytill' ); ?></option>
				<template x-for="b in branches" :key="b.id"><option :value="b.id" x-text="b.name"></option></template>
				<option value="__none__"><?php esc_html_e( 'Sin sucursal (quitar)', 'keytill' ); ?></option>
			</select>
			<button type="button" class="prod__link" @click="clearBranchSel()"><?php esc_html_e( 'Limpiar', 'keytill' ); ?></button>
			<button type="button" class="prod__new" :disabled="branchBusy || !branchTarget || (!branchAll && branchSelCount < 1)" @click="assignBranch()"><?php esc_html_e( 'Asignar', 'keytill' ); ?></button>
		</div>

		<div class="prod__list">
			<table class="prod__table">
				<thead>
					<tr>
						<th class="prod__c-exp"></th>
						<th><?php esc_html_e( 'Producto', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Cód. barras', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Categoría', 'keytill' ); ?></th>
						<th class="n"><?php esc_html_e( 'Precio', 'keytill' ); ?></th>
						<th class="n"><?php esc_html_e( 'Stock', 'keytill' ); ?></th>
						<th class="c"><?php esc_html_e( 'Activo', 'keytill' ); ?></th>
					</tr>
				</thead>
					<template x-for="r in items" :key="r.id">
							<tbody class="prod__group">
							<tr class="prod__row" :class="{ 'is-off': !r.active, 'is-saved': r._saved }">
								<td class="prod__c-exp">
									<button type="button" class="prod__exp" x-show="r.type === 'variable'" @click="toggleExpand(r)"
										:class="{ 'is-open': expanded[r.id] }" aria-label="<?php esc_attr_e( 'Ver variaciones', 'keytill' ); ?>">›</button>
									<input type="checkbox" class="prod__check" x-show="selMode === 'labels' && r.type !== 'variable' && r.barcode" x-cloak
										:checked="isLabelSel(r.id)" @change="toggleLabelSel(r, r.name)"
										title="<?php esc_attr_e( 'Seleccionar para etiquetas', 'keytill' ); ?>" />
									<input type="checkbox" class="prod__check" x-show="selMode === 'branch' && r.type !== 'variable'" x-cloak
										:checked="isBranchSel(r.id)" @change="toggleBranchSel(r)"
										title="<?php esc_attr_e( 'Seleccionar para sucursal', 'keytill' ); ?>" />
								</td>
								<td class="prod__name">
									<span class="prod__thumb" x-show="r.image" x-cloak><img :src="r.image" alt="" loading="lazy" /></span>
									<template x-if="canManage">
										<button type="button" class="prod__namebtn" @click="openEditor(r)" x-text="r.name" title="<?php esc_attr_e( 'Editar producto', 'keytill' ); ?>"></button>
									</template>
									<template x-if="!canManage"><span x-text="r.name"></span></template>
									<template x-if="r.type === 'variable'"><span class="prod__tag" x-text="r.variations + ' var.'"></span></template>
									<template x-if="r.giftcard"><span class="prod__tag prod__tag--gc">gift</span></template>
									<template x-if="r.type === 'variable'"><span class="prod__branch"><?php esc_html_e( 'Sucursal: por variación', 'keytill' ); ?></span></template>
									<template x-if="r.type !== 'variable' && branches.length">
										<span class="prod__branch">
											<template x-for="(seg, si) in branchInfo(r)" :key="si">
												<span :class="seg.cls"><template x-if="si > 0"><span class="bs-sep"> · </span></template><span x-text="seg.txt"></span></span>
											</template>
										</span>
									</template>
								</td>
								<td class="mono muted" x-text="r.sku || '—'"></td>
								<td class="mono muted" x-text="r.type === 'variable' ? 'por variación' : (r.barcode || '—')"></td>
								<td class="muted" x-text="r.category || '—'"></td>

								<!-- Precio -->
								<td class="n">
									<template x-if="r.editable && canManage">
										<input type="text" inputmode="decimal" class="prod__inp mono"
											x-model="r.regular_price" @keydown.enter="$event.target.blur()" @blur="saveField(r, 'regular_price')" />
									</template>
									<template x-if="!(r.editable && canManage)">
										<span class="mono" x-html="r.price_html"></span>
									</template>
								</td>

								<!-- Stock -->
								<td class="n">
									<!-- Distribuido: no se edita raw acá (rompería el reparto por sucursal);
									     se muestra el total y se edita en el producto. -->
									<template x-if="r.editable && canManage && r.branch_managed">
										<button type="button" class="prod__stockbtn mono" @click="openEditor(r)" x-text="r.stock_qty"
											title="<?php esc_attr_e( 'Distribuido por sucursal — editar en el producto', 'keytill' ); ?>"></button>
									</template>
									<template x-if="r.editable && canManage && !r.branch_managed">
										<input type="text" inputmode="numeric" class="prod__inp prod__inp--stock mono" placeholder="—"
											x-model="r._stockStr" @keydown.enter="$event.target.blur()" @blur="saveField(r, 'stock_quantity')" />
									</template>
									<template x-if="!(r.editable && canManage)">
										<span class="mono" :class="{ 'prod__oos': !r.in_stock }"
											x-text="r.type === 'variable' ? '—' : (r.manage_stock ? r.stock_qty : (r.in_stock ? '∞' : 'sin stock'))"></span>
									</template>
								</td>

								<!-- Activo -->
								<td class="c">
									<button type="button" class="prod__toggle" :class="{ 'is-on': r.active }"
										:disabled="!canManage" @click="toggleActive(r)" :aria-pressed="r.active"><span></span></button>
								</td>
							</tr>

							<!-- Variaciones -->
							<template x-if="expanded[r.id]">
								<tr class="prod__varwrap">
									<td></td>
									<td colspan="7">
										<div class="prod__varloading" x-show="expanded[r.id].loading"><?php esc_html_e( 'Cargando variaciones…', 'keytill' ); ?></div>
										<div class="prod__err" x-show="expanded[r.id].error" x-text="expanded[r.id].error"></div>
										<table class="prod__vartable" x-show="!expanded[r.id].loading">
											<thead x-show="(selMode === 'labels' && labelSelectableVars(r).length) || (selMode === 'branch' && branchVars(r).length)" x-cloak>
												<tr class="prod__varhead">
													<th class="prod__varcheck">
														<input type="checkbox" class="prod__check" x-show="selMode === 'labels'" :checked="allVarsSelected(r)" @change="toggleAllVars(r)"
															title="<?php esc_attr_e( 'Seleccionar todas', 'keytill' ); ?>" />
														<input type="checkbox" class="prod__check" x-show="selMode === 'branch'" :checked="allBranchVarsSelected(r)" @change="toggleAllBranchVars(r)"
															title="<?php esc_attr_e( 'Seleccionar todas', 'keytill' ); ?>" />
													</th>
													<th colspan="5" class="mono muted">
														<span x-show="selMode === 'labels'"><?php esc_html_e( 'Seleccionar todas (con código de barras) para etiquetas', 'keytill' ); ?></span>
														<span x-show="selMode === 'branch'"><?php esc_html_e( 'Seleccionar todas para asignar sucursal', 'keytill' ); ?></span>
													</th>
												</tr>
											</thead>
											<template x-for="v in expanded[r.id].rows" :key="v.id">
												<tr class="prod__varrow" :class="{ 'is-saved': v._saved }">
													<td class="prod__varcheck">
														<input type="checkbox" class="prod__check" x-show="selMode === 'labels' && v.barcode" x-cloak
															:checked="isLabelSel(v.id)" @change="toggleLabelSel(v, r.name + ' — ' + (v.label || ''))"
															title="<?php esc_attr_e( 'Seleccionar para etiquetas', 'keytill' ); ?>" />
														<input type="checkbox" class="prod__check" x-show="selMode === 'branch'" x-cloak
															:checked="isBranchSel(v.id)" @change="toggleBranchSel(v)"
															title="<?php esc_attr_e( 'Seleccionar para sucursal', 'keytill' ); ?>" />
													</td>
													<td class="prod__varname">
														<span x-text="v.label || '—'"></span>
														<span class="prod__branch" x-show="branches.length" x-cloak>
															<template x-for="(seg, si) in branchInfo(v)" :key="si">
																<span :class="seg.cls"><template x-if="si > 0"><span class="bs-sep"> · </span></template><span x-text="seg.txt"></span></span>
															</template>
														</span>
													</td>
													<td class="mono muted" x-text="v.sku || '—'"></td>
													<td>
														<template x-if="canManage">
															<input type="text" inputmode="numeric" maxlength="12" class="prod__inp mono" placeholder="12 díg."
																x-model="v.barcode" @keydown.enter="$event.target.blur()" @blur="saveField(v, 'barcode')" autocomplete="off" />
														</template>
														<template x-if="!canManage"><span class="mono muted" x-text="v.barcode || '—'"></span></template>
													</td>
													<td class="n">
														<template x-if="canManage">
															<input type="text" inputmode="decimal" class="prod__inp mono"
																x-model="v.regular_price" @keydown.enter="$event.target.blur()" @blur="saveField(v, 'regular_price')" />
														</template>
														<template x-if="!canManage"><span class="mono" x-text="money(v.regular_price)"></span></template>
													</td>
													<td class="n">
														<template x-if="canManage && v.branch_managed">
															<button type="button" class="prod__stockbtn mono" @click="openEditor(r)" x-text="v.stock_qty"
																title="<?php esc_attr_e( 'Distribuido por sucursal — editar en el producto', 'keytill' ); ?>"></button>
														</template>
														<template x-if="canManage && !v.branch_managed">
															<input type="text" inputmode="numeric" class="prod__inp prod__inp--stock mono" placeholder="—"
																x-model="v._stockStr" @keydown.enter="$event.target.blur()" @blur="saveField(v, 'stock_quantity')" />
														</template>
														<template x-if="!canManage">
															<span class="mono" :class="{ 'prod__oos': !v.in_stock }" x-text="v.manage_stock ? v.stock_qty : (v.in_stock ? '∞' : 'sin stock')"></span>
														</template>
													</td>
												</tr>
											</template>
										</table>
									</td>
								</tr>
							</template>
							</tbody>
					</template>
			</table>
			<div class="prod__empty" x-show="!loading && !items.length"><?php esc_html_e( 'Sin productos.', 'keytill' ); ?></div>
			<div class="prod__empty" x-show="loading"><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>
		</div>

		<div class="prod__foot">
			<span class="mono muted"><span x-text="total"></span> <?php esc_html_e( 'productos', 'keytill' ); ?></span>
			<div class="prod__pager" x-show="pages > 1">
				<button type="button" @click="goPage(page - 1)" :disabled="page <= 1">‹</button>
				<span class="mono"><span x-text="page"></span> / <span x-text="pages"></span></span>
				<button type="button" @click="goPage(page + 1)" :disabled="page >= pages">›</button>
			</div>
		</div>

		<!-- Etiquetas en lote (multi-selección) -->
		<div class="prod__modal" x-show="labelsBatchOpen" x-cloak @click.self="closeLabelsBatch()">
			<div class="prod__card prod__card--labels">
				<span class="modal__title"><?php esc_html_e( 'GENERAR ETIQUETAS', 'keytill' ); ?></span>
				<span class="mono modal__sub"><?php esc_html_e( 'Cantidad de etiquetas por producto', 'keytill' ); ?></span>
				<div class="lblbatch__list">
					<template x-for="it in labelSelRows()" :key="it.id">
						<div class="lblbatch__row">
							<div class="lblbatch__info">
								<span class="lblbatch__title" x-text="it.title"></span>
								<span class="mono muted" x-text="it.barcode"></span>
							</div>
							<input type="number" min="1" max="1000" class="modal__input mono lblbatch__qty" x-model.number="it.qty" />
							<button type="button" class="lblbatch__rm" @click="removeLabelSel(it.id)" title="<?php esc_attr_e( 'Quitar', 'keytill' ); ?>">✕</button>
						</div>
					</template>
				</div>
				<div class="lblbatch__foot mono muted"><span x-text="labelSelTotal()"></span> <?php esc_html_e( 'etiquetas en total', 'keytill' ); ?></div>
				<div class="prod__modal-actions">
					<button type="button" class="prod__cancel" @click="closeLabelsBatch()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<button type="button" class="prod__save" @click="confirmLabelsBatch()">🏷 <?php esc_html_e( 'Imprimir', 'keytill' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Editor de producto (alta + edición) -->
		<div class="orders__drawer" x-show="editorOpen" x-cloak @click.self="closeEditor()">
			<aside class="drawer prod__editor" x-cloak>
				<div class="drawer__head">
					<div>
						<span class="drawer__num" x-text="editorMode === 'create' ? '<?php echo esc_js( __( 'Nuevo producto', 'keytill' ) ); ?>' : '<?php echo esc_js( __( 'Editar producto', 'keytill' ) ); ?>'"></span>
						<span class="mono muted" x-show="form && form.type === 'variable'" x-cloak><?php esc_html_e( 'Producto variable', 'keytill' ); ?></span>
					</div>
					<button type="button" class="drawer__close" @click="closeEditor()" aria-label="Cerrar">✕</button>
				</div>

				<div class="drawer__loading" x-show="editorBusy && !form.name" x-cloak><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>

				<template x-if="form">
					<div class="drawer__body prod__form">
						<!-- Tipo (solo al crear) -->
						<template x-if="editorMode === 'create'">
							<div class="prod__type">
								<label class="prod__type-opt" :class="{ 'is-on': form.type === 'simple' }">
									<input type="radio" value="simple" x-model="form.type" hidden /><?php esc_html_e( 'Simple', 'keytill' ); ?>
								</label>
								<label class="prod__type-opt" :class="{ 'is-on': form.type === 'variable' }">
									<input type="radio" value="variable" x-model="form.type" hidden /><?php esc_html_e( 'Variable (talles / colores)', 'keytill' ); ?>
								</label>
							</div>
						</template>

						<!-- Imagen -->
						<div class="prod__image">
							<div class="prod__image-preview" :class="{ 'is-empty': !form.image_url }">
								<template x-if="form.image_url"><img :src="form.image_url" alt="" /></template>
								<template x-if="!form.image_url"><span class="mono muted"><?php esc_html_e( 'sin imagen', 'keytill' ); ?></span></template>
							</div>
							<div class="prod__image-actions">
								<span class="prod__image-title mono muted"><?php esc_html_e( 'Imagen principal', 'keytill' ); ?></span>
								<label class="prod__imgbtn">
									<span x-show="!uploadBusy"><?php esc_html_e( 'Subir imagen', 'keytill' ); ?></span>
									<span x-show="uploadBusy" x-cloak><?php esc_html_e( 'Subiendo…', 'keytill' ); ?></span>
									<input type="file" accept="image/*" @change="uploadImage($event)" hidden />
								</label>
								<button type="button" class="prod__imglink" x-show="form.image_url" @click="removeImage()"><?php esc_html_e( 'Quitar', 'keytill' ); ?></button>
							</div>
						</div>

						<!-- Galería (imágenes adicionales del producto) -->
						<div class="prod__gallery">
							<div class="prod__gallery-head">
								<span class="prod__image-title mono muted"><?php esc_html_e( 'Galería', 'keytill' ); ?></span>
								<label class="prod__imglink prod__gallery-add">
									<span x-show="!uploadBusy"><?php esc_html_e( '+ agregar imágenes', 'keytill' ); ?></span>
									<span x-show="uploadBusy" x-cloak><?php esc_html_e( 'Subiendo…', 'keytill' ); ?></span>
									<input type="file" accept="image/*" multiple @change="uploadGallery($event)" hidden />
								</label>
							</div>
							<div class="prod__gallery-grid" x-show="form.gallery.length" x-cloak>
								<template x-for="(g, gi) in form.gallery" :key="g.id">
									<div class="prod__gallery-item">
										<img :src="g.url" alt="" loading="lazy" />
										<button type="button" class="prod__gallery-del" @click="removeGallery(gi)" title="<?php esc_attr_e( 'Quitar de la galería', 'keytill' ); ?>">✕</button>
									</div>
								</template>
							</div>
							<div class="mono muted prod__gallery-empty" x-show="!form.gallery.length" x-cloak><?php esc_html_e( 'Sin imágenes adicionales.', 'keytill' ); ?></div>
						</div>

						<label class="modal__label"><?php esc_html_e( 'Nombre', 'keytill' ); ?>
							<input type="text" x-ref="edName" x-model="form.name" class="modal__input" autocomplete="off" />
						</label>
						<div class="modal__grid">
							<label class="modal__label"><?php esc_html_e( 'SKU / código', 'keytill' ); ?>
								<input type="text" x-model="form.sku" class="modal__input mono" placeholder="—" autocomplete="off" />
							</label>
							<label class="modal__label"><?php esc_html_e( 'Categoría', 'keytill' ); ?>
								<select x-model="form.category_id" class="modal__input">
									<option value=""><?php esc_html_e( 'Sin categoría', 'keytill' ); ?></option>
									<template x-for="c in meta.categories" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
								</select>
							</label>
							<label class="modal__label" x-show="branches.length && form.type === 'simple' && !form.branchManaged" x-cloak><?php esc_html_e( 'Sucursal', 'keytill' ); ?>
								<select x-model="form.branch_id" class="modal__input">
									<option value=""><?php esc_html_e( 'Sin sucursal', 'keytill' ); ?></option>
									<template x-for="b in branches" :key="b.id"><option :value="b.id" x-text="b.name"></option></template>
								</select>
								<span class="modal__hint mono muted" x-show="form.branch_id"><?php esc_html_e( 'Todo el stock quedará en esta sucursal.', 'keytill' ); ?></span>
							</label>
						</div>

						<!-- Precio / stock: solo simples (los variables se editan por variación) -->
						<template x-if="form.type === 'simple'">
							<div class="prod__form-block">
								<div class="modal__grid">
									<label class="modal__label"><?php esc_html_e( 'Código de barras', 'keytill' ); ?>
										<input type="text" inputmode="numeric" maxlength="12" x-model="form.barcode" class="modal__input mono" placeholder="<?php esc_attr_e( '12 dígitos', 'keytill' ); ?>" autocomplete="off" />
									</label>
									<label class="modal__label modal__label--check">
										<span><?php esc_html_e( 'Etiquetas', 'keytill' ); ?></span>
										<button type="button" class="prod__imgbtn" @click="openLabels(form)" :disabled="!form.id || !form.barcode"><?php esc_html_e( 'Generar', 'keytill' ); ?></button>
									</label>
								</div>
								<div class="modal__grid">
									<label class="modal__label"><?php esc_html_e( 'Precio', 'keytill' ); ?>
										<input type="text" inputmode="decimal" x-model="form.regular_price" class="modal__input mono" placeholder="0" autocomplete="off" />
									</label>
									<label class="modal__label"><?php esc_html_e( 'Precio oferta (opcional)', 'keytill' ); ?>
										<input type="text" inputmode="decimal" x-model="form.sale_price" class="modal__input mono" placeholder="—" autocomplete="off" />
									</label>
								</div>
								<div class="modal__grid">
									<!-- Stock: único (sin sucursales o "legacy" no distribuido) o por sucursal -->
									<template x-if="!branches.length || !form.branchManaged">
										<label class="modal__label"><?php esc_html_e( 'Stock', 'keytill' ); ?>
											<input type="text" inputmode="numeric" x-model="form.stock_quantity" class="modal__input mono" placeholder="<?php esc_attr_e( 'sin gestionar', 'keytill' ); ?>" autocomplete="off" />
											<button type="button" class="prod__link" x-show="branches.length && !form.branchManaged" @click="form.branchManaged = true"><?php esc_html_e( 'Distribuir por sucursal…', 'keytill' ); ?></button>
										</label>
									</template>
									<template x-if="branches.length && form.branchManaged">
										<div class="modal__label prod__bstock">
											<span class="prod__bstock-head"><?php esc_html_e( 'Stock por sucursal', 'keytill' ); ?>
												<span class="prod__bstock-total mono">· <?php esc_html_e( 'total', 'keytill' ); ?> <span x-text="branchStockTotal(form.branch_stock)"></span></span>
											</span>
											<div class="prod__bstock-rows">
												<template x-for="b in branches" :key="b.id">
													<label class="prod__bstock-row">
														<span class="prod__bstock-name" x-text="b.name"></span>
														<input type="text" inputmode="numeric" class="prod__inp mono" x-model.number="form.branch_stock[b.id]" placeholder="0" autocomplete="off" />
													</label>
												</template>
											</div>
											<button type="button" class="prod__link" x-show="form.id && branches.length > 1" @click="openMove(form.id, form.name)"><?php esc_html_e( 'Mover stock entre sucursales', 'keytill' ); ?></button>
										</div>
									</template>
									<label class="modal__label modal__label--check">
										<span><?php esc_html_e( 'Activo (a la venta)', 'keytill' ); ?></span>
										<button type="button" class="prod__toggle" :class="{ 'is-on': form.active }" @click="form.active = !form.active" :aria-pressed="form.active"><span></span></button>
									</label>
								</div>
							</div>
						</template>
						<!-- Variable: atributos globales + grilla de variaciones -->
						<template x-if="form.type === 'variable'">
							<div class="prod__var">
								<template x-if="!form.id">
									<div class="note-inline mono muted"><?php esc_html_e( 'Guardá primero el producto para definir los atributos y generar las variaciones.', 'keytill' ); ?></div>
								</template>

								<template x-if="form.id">
									<div class="prod__var-inner">
										<div class="prod__var-title"><?php esc_html_e( 'Atributos y variaciones', 'keytill' ); ?></div>
										<div class="prod__var-msg mono muted" x-show="genMsg" x-cloak x-text="genMsg"></div>

										<!-- Atributos elegidos -->
										<template x-for="(row, idx) in form.attrRows" :key="row.taxonomy">
											<div class="prod__attr">
												<div class="prod__attr-head">
													<strong x-text="row.name"></strong>
													<button type="button" class="prod__imglink" @click="removeAttrRow(idx)"><?php esc_html_e( 'quitar', 'keytill' ); ?></button>
												</div>
												<div class="prod__chips">
													<template x-for="t in row.terms" :key="t.id">
														<label class="prod__chip" :class="{ 'is-on': t.on }">
															<input type="checkbox" x-model="t.on" hidden />
															<span x-text="t.name"></span>
														</label>
													</template>
													<span class="mono muted" x-show="!row.terms.length" x-cloak><?php esc_html_e( 'sin valores aún', 'keytill' ); ?></span>
												</div>
												<div class="prod__attr-add">
													<input type="text" class="modal__input mono" x-model="row.newTerm" placeholder="<?php esc_attr_e( 'Nuevo valor (ej. M)', 'keytill' ); ?>"
														@keydown.enter.prevent="createTerm(row)" autocomplete="off" />
													<button type="button" class="prod__imgbtn" @click="createTerm(row)" :disabled="genBusy"><?php esc_html_e( '+ valor', 'keytill' ); ?></button>
												</div>
											</div>
										</template>

										<!-- Agregar atributo (existente o nuevo) -->
										<div class="prod__attr-pick">
											<select class="modal__input" x-model="pickAttr" @change="addAttrRow()">
												<option value=""><?php esc_html_e( 'Agregar atributo existente…', 'keytill' ); ?></option>
												<template x-for="a in availableAttrs()" :key="a.id"><option :value="a.taxonomy" x-text="a.name"></option></template>
											</select>
											<span class="mono muted"><?php esc_html_e( 'o', 'keytill' ); ?></span>
											<input type="text" class="modal__input" x-model="newAttrName" placeholder="<?php esc_attr_e( 'Crear atributo (ej. Talle)', 'keytill' ); ?>"
												@keydown.enter.prevent="createAttribute()" autocomplete="off" />
											<button type="button" class="prod__imgbtn" @click="createAttribute()" :disabled="genBusy"><?php esc_html_e( '+ atributo', 'keytill' ); ?></button>
										</div>

										<button type="button" class="prod__save prod__gen" @click="generateVariations()" :disabled="genBusy || !form.attrRows.length">
											<span x-show="!genBusy"><?php esc_html_e( 'Generar variaciones', 'keytill' ); ?></span>
											<span x-show="genBusy" x-cloak><?php esc_html_e( 'Generando…', 'keytill' ); ?></span>
										</button>

										<!-- Grilla de variaciones (scroll horizontal si no entra) -->
										<div class="prod__gridwrap" x-show="form.variations.length" x-cloak>
										<table class="prod__vartable prod__gridtable">
											<thead>
												<tr>
													<th><?php esc_html_e( 'Variación', 'keytill' ); ?></th>
													<th><?php esc_html_e( 'SKU', 'keytill' ); ?></th>
													<th><?php esc_html_e( 'Cód. barras', 'keytill' ); ?></th>
													<th class="n"><?php esc_html_e( 'Precio', 'keytill' ); ?></th>
													<th class="n"><?php esc_html_e( 'Stock', 'keytill' ); ?></th>
													<th x-show="branches.length" x-cloak><?php esc_html_e( 'Sucursal', 'keytill' ); ?></th>
													<th></th>
												</tr>
											</thead>
											<tbody>
												<template x-for="v in form.variations" :key="v.id">
													<tr class="prod__varrow" :class="{ 'is-saved': v._saved }">
														<td class="prod__varname" x-text="v.label || '—'"></td>
														<td><input type="text" class="prod__inp mono" x-model="v.sku" placeholder="—"
															@keydown.enter="$event.target.blur()" @blur="saveField(v, 'sku')" autocomplete="off" /></td>
														<td><input type="text" inputmode="numeric" maxlength="12" class="prod__inp prod__inp--code mono" x-model="v.barcode" placeholder="12 díg."
															@keydown.enter="$event.target.blur()" @blur="saveField(v, 'barcode')" autocomplete="off" /></td>
														<td class="n"><input type="text" inputmode="decimal" class="prod__inp mono" x-model="v.regular_price"
															@keydown.enter="$event.target.blur()" @blur="saveField(v, 'regular_price')" /></td>
														<td class="n">
															<!-- Sin sucursales o variación "legacy": stock único (con opción de
															     distribuir). Ya distribuida: total que abre el popover. -->
															<template x-if="!branches.length">
																<input type="text" inputmode="numeric" class="prod__inp prod__inp--stock mono" placeholder="—" x-model="v._stockStr"
																	@keydown.enter="$event.target.blur()" @blur="saveField(v, 'stock_quantity')" />
															</template>
															<template x-if="branches.length && v.branch_managed">
																<button type="button" class="prod__stockbtn mono" @click="openStockPop(v)" x-text="(v.stock_total === null || v.stock_total === undefined) ? '—' : v.stock_total" title="<?php esc_attr_e( 'Stock por sucursal', 'keytill' ); ?>"></button>
															</template>
															<template x-if="branches.length && !v.branch_managed">
																<span class="prod__stocklegacy">
																	<input type="text" inputmode="numeric" class="prod__inp prod__inp--stock mono" placeholder="—" x-model="v._stockStr"
																		@keydown.enter="$event.target.blur()" @blur="saveField(v, 'stock_quantity')" />
																	<button type="button" class="prod__mini" @click="openStockPop(v)" title="<?php esc_attr_e( 'Distribuir por sucursal', 'keytill' ); ?>">⇄</button>
																</span>
															</template>
														</td>
														<td x-show="branches.length" x-cloak>
															<!-- Legacy: elegir sucursal = todo el stock ahí (pasa a distribuido).
															     Ya distribuida: se muestra "Distribuido" y el reparto se edita
															     en la casilla de stock (botón por sucursal). -->
															<template x-if="!v.branch_managed">
																<select class="prod__inp prod__inp--branch" x-model="v.branch_id" @change="saveField(v, 'branch_id')">
																	<option value=""><?php esc_html_e( 'Sin sucursal', 'keytill' ); ?></option>
																	<template x-for="b in branches" :key="b.id"><option :value="b.id" x-text="b.name"></option></template>
																</select>
															</template>
															<template x-if="v.branch_managed">
																<span class="prod__branch-dist mono muted"><?php esc_html_e( 'Distribuido', 'keytill' ); ?></span>
															</template>
														</td>
														<td class="c">
															<button type="button" class="prod__varlabel" @click="openLabels(v)" x-show="v.barcode" title="<?php esc_attr_e( 'Etiquetas', 'keytill' ); ?>">🏷</button>
															<button type="button" class="prod__vardel" @click="deleteVariation(v)" title="<?php esc_attr_e( 'Borrar variación', 'keytill' ); ?>">✕</button>
														</td>
													</tr>
												</template>
											</tbody>
										</table>
										</div>
										<div class="note-inline mono muted" x-show="!form.variations.length" x-cloak><?php esc_html_e( 'Elegí atributos y sus valores, después «Generar variaciones».', 'keytill' ); ?></div>
									</div>
								</template>
							</div>
						</template>

						<div class="modal__label"><?php esc_html_e( 'Descripción corta', 'keytill' ); ?>
							<div class="rte">
								<div class="rte__bar">
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('bold','short')" title="<?php esc_attr_e( 'Negrita', 'keytill' ); ?>"><b>B</b></button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('italic','short')" title="<?php esc_attr_e( 'Itálica', 'keytill' ); ?>"><i>I</i></button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('underline','short')" title="<?php esc_attr_e( 'Subrayado', 'keytill' ); ?>"><u>U</u></button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('insertUnorderedList','short')" title="<?php esc_attr_e( 'Lista', 'keytill' ); ?>">•</button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('insertOrderedList','short')" title="<?php esc_attr_e( 'Lista numerada', 'keytill' ); ?>">1.</button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteLink('short')" title="<?php esc_attr_e( 'Enlace', 'keytill' ); ?>">🔗</button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('removeFormat','short')" title="<?php esc_attr_e( 'Quitar formato', 'keytill' ); ?>">✕</button>
								</div>
								<div class="rte__area" contenteditable="true" x-ref="rteShort" data-ph="<?php esc_attr_e( 'Resumen breve (opcional)', 'keytill' ); ?>"
									@input="form.short_description = $refs.rteShort.innerHTML" @blur="form.short_description = $refs.rteShort.innerHTML"></div>
							</div>
						</div>
						<div class="modal__label"><?php esc_html_e( 'Descripción', 'keytill' ); ?>
							<div class="rte">
								<div class="rte__bar">
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('bold','desc')" title="<?php esc_attr_e( 'Negrita', 'keytill' ); ?>"><b>B</b></button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('italic','desc')" title="<?php esc_attr_e( 'Itálica', 'keytill' ); ?>"><i>I</i></button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('underline','desc')" title="<?php esc_attr_e( 'Subrayado', 'keytill' ); ?>"><u>U</u></button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('insertUnorderedList','desc')" title="<?php esc_attr_e( 'Lista', 'keytill' ); ?>">•</button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('insertOrderedList','desc')" title="<?php esc_attr_e( 'Lista numerada', 'keytill' ); ?>">1.</button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteLink('desc')" title="<?php esc_attr_e( 'Enlace', 'keytill' ); ?>">🔗</button>
									<button type="button" class="rte__btn" @mousedown.prevent @click="rteCmd('removeFormat','desc')" title="<?php esc_attr_e( 'Quitar formato', 'keytill' ); ?>">✕</button>
								</div>
								<div class="rte__area rte__area--tall" contenteditable="true" x-ref="rteDesc" data-ph="<?php esc_attr_e( 'Detalle del producto (opcional)', 'keytill' ); ?>"
									@input="form.description = $refs.rteDesc.innerHTML" @blur="form.description = $refs.rteDesc.innerHTML"></div>
							</div>
						</div>

						<span class="cust__error mono" x-show="editorError" x-text="editorError"></span>
						<div class="prod__modal-actions">
							<button type="button" class="prod__delete" x-show="form.id" x-cloak :disabled="genBusy || editorBusy" @click="deleteProduct()"><?php esc_html_e( 'Eliminar', 'keytill' ); ?></button>
							<span class="prod__modal-spacer"></span>
							<button type="button" class="prod__cancel" @click="closeEditor()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
							<button type="button" class="prod__save" :disabled="editorBusy || uploadBusy" @click="saveEditor()">
								<span x-show="!editorBusy"><?php esc_html_e( 'Guardar', 'keytill' ); ?></span>
								<span x-show="editorBusy" x-cloak><?php esc_html_e( 'Guardando…', 'keytill' ); ?></span>
							</button>
						</div>
					</div>
				</template>
			</aside>
		</div>

		<!-- ============ POPOVER: STOCK POR SUCURSAL (variación) ============ -->
		<div class="prod__modal" x-show="stockPop.open" x-cloak @click.self="closeStockPop()">
			<div class="prod__modal-card prod__stockcard">
				<div class="prod__modal-head">
					<span class="modal__title"><?php esc_html_e( 'Stock por sucursal', 'keytill' ); ?></span>
					<span class="mono muted" x-text="stockPop.name"></span>
				</div>
				<!-- Variación aún no distribuida: se muestra su stock actual como
				     referencia para repartirlo por sucursal. -->
				<div class="note-inline mono muted" x-show="stockPop.legacy" x-cloak>
					<?php esc_html_e( 'Stock actual sin distribuir:', 'keytill' ); ?> <b x-text="stockPop.current"></b>.
					<?php esc_html_e( 'Cargá cuántas hay en cada sucursal.', 'keytill' ); ?>
				</div>
				<div class="prod__bstock-rows">
					<template x-for="b in branches" :key="b.id">
						<label class="prod__bstock-row">
							<span class="prod__bstock-name" x-text="b.name"></span>
							<input type="text" inputmode="numeric" class="prod__inp mono" x-model.number="stockPop.draft[b.id]" placeholder="0" autocomplete="off" />
						</label>
					</template>
				</div>
				<div class="prod__bstock-foot">
					<span class="mono"><?php esc_html_e( 'Total', 'keytill' ); ?>: <b x-text="stockPopTotal()"></b></span>
					<button type="button" class="prod__link" x-show="branches.length > 1" @click="openMove(stockPop.id, stockPop.name)"><?php esc_html_e( 'Mover stock', 'keytill' ); ?></button>
				</div>
				<div class="cust__error mono" x-show="stockPop.error" x-cloak x-text="stockPop.error"></div>
				<div class="prod__modal-actions">
					<button type="button" class="prod__cancel" @click="closeStockPop()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<span class="prod__modal-spacer"></span>
					<button type="button" class="prod__save" @click="saveStockPop()" :disabled="stockPop.busy"><?php esc_html_e( 'Guardar', 'keytill' ); ?></button>
				</div>
			</div>
		</div>

		<!-- ============ DIÁLOGO: MOVER STOCK ENTRE SUCURSALES ============ -->
		<div class="prod__modal" x-show="moveBox.open" x-cloak @click.self="closeMove()">
			<div class="prod__modal-card prod__movecard">
				<div class="prod__modal-head">
					<span class="modal__title"><?php esc_html_e( 'Mover stock', 'keytill' ); ?></span>
					<span class="mono muted" x-text="moveBox.name"></span>
				</div>
				<div class="modal__grid">
					<label class="modal__label"><?php esc_html_e( 'Desde', 'keytill' ); ?>
						<select class="modal__input" x-model="moveBox.from">
							<template x-for="b in branches" :key="b.id"><option :value="b.id" x-text="b.name"></option></template>
						</select>
					</label>
					<label class="modal__label"><?php esc_html_e( 'Hacia', 'keytill' ); ?>
						<select class="modal__input" x-model="moveBox.to">
							<template x-for="b in branches" :key="b.id"><option :value="b.id" x-text="b.name"></option></template>
						</select>
					</label>
				</div>
				<label class="modal__label"><?php esc_html_e( 'Cantidad', 'keytill' ); ?>
					<input type="text" inputmode="numeric" class="modal__input mono" x-model="moveBox.qty" placeholder="0" autocomplete="off" />
				</label>
				<div class="cust__error mono" x-show="moveBox.error" x-cloak x-text="moveBox.error"></div>
				<div class="prod__modal-actions">
					<button type="button" class="prod__cancel" @click="closeMove()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<span class="prod__modal-spacer"></span>
					<button type="button" class="prod__save" @click="doMove()" :disabled="moveBox.busy"><?php esc_html_e( 'Mover', 'keytill' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- ================= VISTA: CLIENTES ================= -->
	<div class="keytill-view keytill-customers" x-show="$store.pos.view === 'clientes'" x-data="keytillCustomers()" x-cloak>

		<div class="cx__bar">
			<input type="search" class="cx__search" placeholder="<?php esc_attr_e( 'Buscar por nombre, email o DNI…', 'keytill' ); ?>"
				x-model="search" @input="onSearch()" @keydown.enter="load(1)" autocomplete="off" />
			<span class="cx__spacer"></span>
			<button type="button" class="cx__new" @click="openCreate()">+ <?php esc_html_e( 'Nuevo cliente', 'keytill' ); ?></button>
		</div>

		<div class="prod__err" x-show="error" x-cloak x-text="error"></div>

		<div class="cx__list">
			<table class="cx__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Cliente', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Email', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'DNI / CUIT', 'keytill' ); ?></th>
						<th><?php esc_html_e( 'Cond. fiscal', 'keytill' ); ?></th>
						<th class="n"><?php esc_html_e( 'Pedidos', 'keytill' ); ?></th>
						<th class="n"><?php esc_html_e( 'Total gastado', 'keytill' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="c in items" :key="c.id">
						<tr class="cx__row" @click="open(c.id)">
							<td class="cx__name" x-text="c.name || '<?php esc_attr_e( '(sin nombre)', 'keytill' ); ?>'"></td>
							<td class="mono muted" x-text="c.email || '—'"></td>
							<td class="mono" x-text="c.doc || '—'"></td>
							<td class="muted" x-text="c.iva"></td>
							<td class="n mono" x-text="c.stats.orders_count"></td>
							<td class="n mono" x-text="c.stats.total_spent_f"></td>
						</tr>
					</template>
				</tbody>
			</table>
			<div class="prod__empty" x-show="!loading && !items.length"><?php esc_html_e( 'Sin clientes.', 'keytill' ); ?></div>
			<div class="prod__empty" x-show="loading"><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>
		</div>

		<div class="prod__foot">
			<span class="mono muted"><span x-text="total"></span> <?php esc_html_e( 'clientes', 'keytill' ); ?></span>
			<div class="prod__pager" x-show="pages > 1">
				<button type="button" @click="goPage(page - 1)" :disabled="page <= 1">‹</button>
				<span class="mono"><span x-text="page"></span> / <span x-text="pages"></span></span>
				<button type="button" @click="goPage(page + 1)" :disabled="page >= pages">›</button>
			</div>
		</div>

		<!-- Detalle / edición (drawer) -->
		<div class="orders__drawer" x-show="selected || detailLoading" x-cloak @click.self="closeDetail()">
			<aside class="drawer">
				<div class="drawer__head">
					<div>
						<span class="drawer__num"><?php esc_html_e( 'Cliente', 'keytill' ); ?></span>
						<span class="mono muted" x-text="selected ? ('#' + selected.id) : ''"></span>
					</div>
					<button type="button" class="drawer__close" @click="closeDetail()" aria-label="Cerrar">✕</button>
				</div>

				<div class="drawer__loading" x-show="detailLoading"><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>

				<template x-if="selected">
					<div class="drawer__body">
						<!-- Estadísticas -->
						<div class="cx__stats">
							<div class="cx__stat">
								<span class="cx__stat-num mono" x-text="selected.stats.orders_count"></span>
								<span class="cx__stat-lbl"><?php esc_html_e( 'pedidos', 'keytill' ); ?></span>
							</div>
							<div class="cx__stat">
								<span class="cx__stat-num mono" x-text="selected.stats.total_spent_f"></span>
								<span class="cx__stat-lbl"><?php esc_html_e( 'total gastado', 'keytill' ); ?></span>
							</div>
						</div>

						<!-- Edición -->
						<div class="cx__edit">
							<label class="modal__label"><?php esc_html_e( 'Nombre', 'keytill' ); ?>
								<input type="text" x-model="selected.name" class="modal__input" autocomplete="off" />
							</label>
							<div class="modal__grid">
								<label class="modal__label"><?php esc_html_e( 'Email', 'keytill' ); ?>
									<input type="email" x-model="selected.email" class="modal__input mono" placeholder="—" autocomplete="off" spellcheck="false" />
								</label>
								<label class="modal__label"><?php esc_html_e( 'DNI / CUIT', 'keytill' ); ?>
									<input type="text" x-model="selected.doc" class="modal__input mono" placeholder="—" autocomplete="off" />
								</label>
							</div>
							<div class="modal__grid">
								<label class="modal__label"><?php esc_html_e( 'Teléfono', 'keytill' ); ?>
									<input type="tel" x-model="selected.phone" class="modal__input mono" placeholder="—" autocomplete="off" />
								</label>
								<label class="modal__label"><?php esc_html_e( 'Fecha de nacimiento', 'keytill' ); ?>
									<input type="date" x-model="selected.birthdate" class="modal__input mono" />
								</label>
							</div>
							<label class="modal__label"><?php esc_html_e( 'Condición fiscal', 'keytill' ); ?>
								<select x-model="selected.iva" class="modal__input">
									<template x-for="opt in (selected.fiscal || fiscalOptions)" :key="opt"><option :value="opt" x-text="opt"></option></template>
								</select>
							</label>
							<span class="cust__error mono" x-show="saveError" x-text="saveError"></span>
							<div class="cx__conflict" x-show="saveConflict" x-cloak>
								<button type="button" class="cust__link" @click="useConflict(saveConflict)"><?php esc_html_e( 'Ver cliente existente', 'keytill' ); ?> ↗</button>
							</div>
							<button type="button" class="prod__save cx__savebtn" :disabled="saveBusy" @click="saveEdit()">
								<span x-show="!saveBusy"><?php esc_html_e( 'Guardar cambios', 'keytill' ); ?></span>
								<span x-show="saveBusy" x-cloak><?php esc_html_e( 'Guardando…', 'keytill' ); ?></span>
							</button>
						</div>

						<!-- Historial de compras -->
						<div class="cx__history">
							<span class="mono muted cx__history-h"><?php esc_html_e( 'Historial de compras', 'keytill' ); ?></span>
							<template x-if="!selected.orders.length">
								<span class="mono muted cx__history-empty"><?php esc_html_e( 'Sin compras registradas.', 'keytill' ); ?></span>
							</template>
							<table class="cx__orders" x-show="selected.orders.length">
								<template x-for="o in selected.orders" :key="o.id">
									<tr>
										<td class="mono">#<span x-text="o.number"></span></td>
										<td class="mono muted" x-text="o.date"></td>
										<td><span class="origin-tag" :class="'is-' + o.origin" x-text="o.origin === 'pos' ? 'POS' : 'Web'"></span></td>
										<td><span class="status-tag" :class="'is-' + o.status" x-text="o.status_label"></span></td>
										<td class="n mono" x-text="o.total_f"></td>
										<td class="c"><button type="button" class="cx__reprint" x-show="o.print_url" @click="reprint(o)" title="<?php esc_attr_e( 'Reimprimir', 'keytill' ); ?>">⎙</button></td>
									</tr>
								</template>
							</table>
						</div>
					</div>
				</template>
			</aside>
		</div>

		<!-- Alta (modal) -->
		<div class="prod__modal" x-show="createOpen" x-cloak @click.self="closeCreate()">
			<div class="prod__card">
				<span class="modal__title"><?php esc_html_e( 'NUEVO CLIENTE', 'keytill' ); ?></span>
				<span class="mono modal__sub"><?php esc_html_e( 'Al menos email o DNI · no se envía ningún email', 'keytill' ); ?></span>
				<label class="modal__label"><?php esc_html_e( 'Nombre', 'keytill' ); ?>
					<input type="text" x-ref="newName" x-model="newCust.name" class="modal__input" autocomplete="off" @keydown.enter="submitCreate()" />
				</label>
				<div class="modal__grid">
					<label class="modal__label"><?php esc_html_e( 'Email', 'keytill' ); ?>
						<input type="email" x-model="newCust.email" class="modal__input mono" placeholder="—" autocomplete="off" spellcheck="false" @keydown.enter="submitCreate()" />
					</label>
					<label class="modal__label"><?php esc_html_e( 'DNI / CUIT', 'keytill' ); ?>
						<input type="text" x-model="newCust.doc" class="modal__input mono" placeholder="—" autocomplete="off" @keydown.enter="submitCreate()" />
					</label>
				</div>
				<div class="modal__grid">
					<label class="modal__label"><?php esc_html_e( 'Teléfono', 'keytill' ); ?>
						<input type="tel" x-model="newCust.phone" class="modal__input mono" placeholder="—" autocomplete="off" @keydown.enter="submitCreate()" />
					</label>
					<label class="modal__label"><?php esc_html_e( 'Fecha de nacimiento', 'keytill' ); ?>
						<input type="date" x-model="newCust.birthdate" class="modal__input mono" @keydown.enter="submitCreate()" />
					</label>
				</div>
				<label class="modal__label"><?php esc_html_e( 'Condición fiscal', 'keytill' ); ?>
					<select x-model="newCust.iva" class="modal__input">
						<template x-for="opt in fiscalOptions" :key="opt"><option :value="opt" x-text="opt"></option></template>
					</select>
				</label>
				<span class="cust__error mono" x-show="createError" x-text="createError"></span>
				<div class="cx__conflict" x-show="createConflict" x-cloak>
					<button type="button" class="cust__link" @click="useConflict(createConflict)"><?php esc_html_e( 'Ver cliente existente', 'keytill' ); ?> ↗</button>
				</div>
				<div class="prod__modal-actions">
					<button type="button" class="prod__cancel" @click="closeCreate()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<button type="button" class="prod__save" :disabled="createBusy" @click="submitCreate()">
						<span x-show="!createBusy"><?php esc_html_e( 'Crear cliente', 'keytill' ); ?></span>
						<span x-show="createBusy" x-cloak><?php esc_html_e( 'Creando…', 'keytill' ); ?></span>
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- ================= VISTA: CAJA / BANCO ================= -->
	<div class="keytill-view keytill-cashbank" x-show="$store.pos.view === 'cajabanco'" x-data="keytillCashBank()" x-cloak>
		<div class="cb__head">
			<h2 class="cb__title"><?php esc_html_e( 'Caja / Banco', 'keytill' ); ?></h2>
			<button type="button" class="prod__link" @click="load()"><?php esc_html_e( 'Actualizar', 'keytill' ); ?></button>
		</div>
		<div class="prod__err" x-show="error" x-cloak x-text="error"></div>
		<div class="cb__loading mono muted" x-show="loading" x-cloak><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>

		<div class="cb__grid" x-show="!loading" x-cloak>
			<!-- ---------- Caja física (efectivo) ---------- -->
			<section class="cb__card">
				<div class="cb__card-head">
					<span class="cb__card-title"><?php esc_html_e( 'Caja física (efectivo)', 'keytill' ); ?></span>
					<span class="cb__badge" :class="cash ? 'is-open' : 'is-closed'" x-text="cash ? 'Abierta' : 'Cerrada'"></span>
				</div>
				<template x-if="!cash">
					<p class="cb__empty mono muted"><?php esc_html_e( 'No hay caja física abierta. Se abre desde Venta.', 'keytill' ); ?></p>
				</template>
				<template x-if="cash">
					<div>
						<div class="cb__rows">
							<div class="cb__row" x-show="cash.branch_name"><span><?php esc_html_e( 'Sucursal', 'keytill' ); ?></span><span class="mono" x-text="cash.branch_name"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Fondo inicial', 'keytill' ); ?></span><span class="mono" x-text="money(cash.opening_float)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Ventas efectivo', 'keytill' ); ?></span><span class="mono" x-text="money(cash.cash_sales)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Ingresos', 'keytill' ); ?></span><span class="mono" x-text="money(cash.deposits_total)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Retiros', 'keytill' ); ?></span><span class="mono" x-text="'− ' + money(cash.withdrawals_total)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Devoluciones efectivo', 'keytill' ); ?></span><span class="mono" x-text="'− ' + money(cash.cash_refunds)"></span></div>
							<div class="cb__row cb__row--total"><span><?php esc_html_e( 'Esperado en cajón', 'keytill' ); ?></span><span class="mono" x-text="money(cash.expected_cash)"></span></div>
						</div>
						<div class="cb__actions">
							<button type="button" class="cb__btn" @click="openMove('efectivo','ingreso')"><?php esc_html_e( 'Ingreso', 'keytill' ); ?></button>
							<button type="button" class="cb__btn" @click="openMove('efectivo','retiro')"><?php esc_html_e( 'Retiro', 'keytill' ); ?></button>
						</div>
						<template x-if="(cash.deposits && cash.deposits.length) || (cash.withdrawals && cash.withdrawals.length)">
							<div class="cb__moves">
								<template x-for="(d, i) in cash.deposits" :key="'d'+i">
									<div class="cb__move"><span class="cb__move-in">+ <span x-text="money(d.amount)"></span></span><span class="cb__move-c" x-text="d.concept || 'Ingreso'"></span><span class="mono muted" x-text="d.at"></span></div>
								</template>
								<template x-for="(w, i) in cash.withdrawals" :key="'w'+i">
									<div class="cb__move"><span class="cb__move-out">− <span x-text="money(w.amount)"></span></span><span class="cb__move-c" x-text="w.reason || 'Retiro'"></span><span class="mono muted" x-text="w.at"></span></div>
								</template>
							</div>
						</template>
					</div>
				</template>
			</section>

			<!-- ---------- Caja digital (cuenta bancaria) ---------- -->
			<section class="cb__card">
				<div class="cb__card-head">
					<span class="cb__card-title"><?php esc_html_e( 'Caja digital (cuenta bancaria)', 'keytill' ); ?></span>
					<span class="cb__badge" :class="(digital && digital.open) ? 'is-open' : 'is-closed'" x-text="(digital && digital.open) ? 'Abierta' : 'Cerrada'"></span>
				</div>
				<template x-if="digital && !digital.open">
					<div>
						<p class="cb__empty mono muted">
							<span x-show="digital.seeded"><?php esc_html_e( 'Caja digital cerrada. Último saldo:', 'keytill' ); ?> <b class="mono" x-text="money(digital.last_balance)"></b></span>
							<span x-show="!digital.seeded"><?php esc_html_e( 'La caja digital nunca se abrió. Al abrirla, cargás el saldo inicial.', 'keytill' ); ?></span>
						</p>
						<div class="cb__actions">
							<button type="button" class="cb__btn cb__btn--primary" :disabled="busy" @click="openDigital()"><?php esc_html_e( 'Abrir caja digital', 'keytill' ); ?></button>
						</div>
					</div>
				</template>
				<template x-if="digital && digital.open">
					<div>
						<div class="cb__rows">
							<div class="cb__row"><span><?php esc_html_e( 'Apertura', 'keytill' ); ?></span><span class="mono" x-text="money(digital.opening)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Cobros (transf./QR/tarjeta)', 'keytill' ); ?></span><span class="mono" x-text="money(digital.sales)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Devoluciones', 'keytill' ); ?></span><span class="mono" x-text="'− ' + money(digital.refunds)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Ingresos', 'keytill' ); ?></span><span class="mono" x-text="money(digital.ingresos)"></span></div>
							<div class="cb__row"><span><?php esc_html_e( 'Retiros', 'keytill' ); ?></span><span class="mono" x-text="'− ' + money(digital.retiros)"></span></div>
							<div class="cb__row cb__row--total"><span><?php esc_html_e( 'Saldo', 'keytill' ); ?></span><span class="mono" x-text="money(digital.balance)"></span></div>
						</div>
						<div class="cb__actions">
							<button type="button" class="cb__btn" @click="openMove('digital','ingreso')"><?php esc_html_e( 'Ingreso', 'keytill' ); ?></button>
							<button type="button" class="cb__btn" @click="openMove('digital','retiro')"><?php esc_html_e( 'Retiro', 'keytill' ); ?></button>
							<button type="button" class="cb__btn cb__btn--warn" :disabled="busy" @click="closeDigital()"><?php esc_html_e( 'Cerrar', 'keytill' ); ?></button>
						</div>
						<template x-if="digital.moves && digital.moves.length">
							<div class="cb__moves">
								<template x-for="(m, i) in digital.moves" :key="'m'+i">
									<div class="cb__move">
										<span :class="m.kind === 'ingreso' ? 'cb__move-in' : 'cb__move-out'" x-text="(m.kind === 'ingreso' ? '+ ' : '− ') + money(m.amount)"></span>
										<span class="cb__move-c" x-text="m.concept || (m.kind === 'ingreso' ? 'Ingreso' : 'Retiro')"></span>
										<span class="mono muted" x-text="m.at"></span>
									</div>
								</template>
							</div>
						</template>
					</div>
				</template>
			</section>
		</div>

		<!-- ---------- Libro de movimientos (histórico filtrable) ---------- -->
		<section class="cb__ledger">
			<div class="cb__ledger-head">
				<h3 class="cb__subtitle"><?php esc_html_e( 'Libro de movimientos', 'keytill' ); ?></h3>
				<div class="cb__ledger-actions">
					<button type="button" class="prod__link" @click="loadLedger()"><?php esc_html_e( 'Actualizar', 'keytill' ); ?></button>
					<button type="button" class="cb__btn" @click="exportCsv()">⬇ <?php esc_html_e( 'Exportar CSV', 'keytill' ); ?></button>
				</div>
			</div>

			<!-- Filtros -->
			<div class="cb__filters">
				<label><?php esc_html_e( 'Desde', 'keytill' ); ?> <input type="date" x-model="lf.from" @change="loadLedger()"></label>
				<label><?php esc_html_e( 'Hasta', 'keytill' ); ?> <input type="date" x-model="lf.to" @change="loadLedger()"></label>
				<select x-model="lf.origen" @change="loadLedger()">
					<option value=""><?php esc_html_e( 'Origen: todos', 'keytill' ); ?></option>
					<option value="local"><?php esc_html_e( 'Local', 'keytill' ); ?></option>
					<option value="web"><?php esc_html_e( 'Web', 'keytill' ); ?></option>
				</select>
				<select x-model="lf.movement" @change="loadLedger()">
					<option value=""><?php esc_html_e( 'Movimiento: todos', 'keytill' ); ?></option>
					<option value="ingreso"><?php esc_html_e( 'Ingreso', 'keytill' ); ?></option>
					<option value="egreso"><?php esc_html_e( 'Egreso', 'keytill' ); ?></option>
				</select>
				<select x-model="lf.type" @change="loadLedger()">
					<option value=""><?php esc_html_e( 'Tipo: todos', 'keytill' ); ?></option>
					<option value="venta"><?php esc_html_e( 'Venta', 'keytill' ); ?></option>
					<option value="ingreso"><?php esc_html_e( 'Ingreso', 'keytill' ); ?></option>
					<option value="retiro"><?php esc_html_e( 'Retiro', 'keytill' ); ?></option>
					<option value="devolucion"><?php esc_html_e( 'Devolución', 'keytill' ); ?></option>
				</select>
				<select x-model="lf.account" @change="loadLedger()">
					<option value=""><?php esc_html_e( 'Cuenta: todas', 'keytill' ); ?></option>
					<option value="efectivo"><?php esc_html_e( 'Efectivo', 'keytill' ); ?></option>
					<option value="digital"><?php esc_html_e( 'Digital', 'keytill' ); ?></option>
				</select>
			</div>

			<!-- Totales del rango -->
			<div class="cb__ledger-totals">
				<div class="cb__tot"><span class="cb__tot-h"><?php esc_html_e( 'Efectivo', 'keytill' ); ?></span>
					<span class="cb__move-in" x-text="'+ ' + money(ledger.totals.efectivo.in)"></span>
					<span class="cb__move-out" x-text="'− ' + money(ledger.totals.efectivo.out)"></span>
					<span class="cb__tot-net"><?php esc_html_e( 'neto', 'keytill' ); ?> <b x-text="money(ledger.totals.efectivo.net)"></b></span>
				</div>
				<div class="cb__tot"><span class="cb__tot-h"><?php esc_html_e( 'Digital', 'keytill' ); ?></span>
					<span class="cb__move-in" x-text="'+ ' + money(ledger.totals.digital.in)"></span>
					<span class="cb__move-out" x-text="'− ' + money(ledger.totals.digital.out)"></span>
					<span class="cb__tot-net"><?php esc_html_e( 'neto', 'keytill' ); ?> <b x-text="money(ledger.totals.digital.net)"></b></span>
				</div>
			</div>

			<div class="cb__loading mono muted" x-show="ledger.loading" x-cloak><?php esc_html_e( 'Cargando…', 'keytill' ); ?></div>
			<div class="cb__empty mono muted" x-show="!ledger.loading && !ledger.rows.length" x-cloak><?php esc_html_e( 'Sin movimientos en el rango.', 'keytill' ); ?></div>

			<div class="cb__tablewrap" x-show="ledger.rows.length" x-cloak>
				<table class="cb__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha', 'keytill' ); ?></th>
							<th><?php esc_html_e( 'Origen', 'keytill' ); ?></th>
							<th><?php esc_html_e( 'Mov.', 'keytill' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'keytill' ); ?></th>
							<th><?php esc_html_e( 'Cuenta', 'keytill' ); ?></th>
							<th class="n"><?php esc_html_e( 'Monto', 'keytill' ); ?></th>
							<th><?php esc_html_e( 'Detalle', 'keytill' ); ?></th>
							<th class="c"><?php esc_html_e( 'Conciliación', 'keytill' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<template x-for="(r, i) in pagedRows()" :key="(lpage - 1) * lperPage + i">
							<tr>
								<td class="mono muted" x-text="r.date"></td>
								<td><span class="cb__tag" :class="r.origen === 'web' ? 'is-web' : 'is-local'" x-text="origenLabel(r.origen)"></span></td>
								<td x-text="movementLabel(r.movement)"></td>
								<td x-text="typeLabel(r.type)"></td>
								<td class="mono muted" x-text="accountLabel(r.account)"></td>
								<td class="n mono" :class="r.movement === 'egreso' ? 'cb__move-out' : 'cb__move-in'" x-text="(r.movement === 'egreso' ? '− ' : '+ ') + money(r.amount)"></td>
								<td class="cb__detail" x-text="r.detail"></td>
								<td class="c">
									<template x-if="r.order_id">
										<span class="cb__verify" :class="'is-' + r.verification">
											<button type="button" class="cb__vbtn cb__vbtn--ok" :class="{ 'is-on': r.verification === 'verificada' }" @click="setVerify(r, 'verificada')" title="<?php esc_attr_e( 'Verificada (entró al banco)', 'keytill' ); ?>">✓</button>
											<button type="button" class="cb__vbtn cb__vbtn--bad" :class="{ 'is-on': r.verification === 'no_llego' }" @click="setVerify(r, 'no_llego')" title="<?php esc_attr_e( 'No llegó', 'keytill' ); ?>">✕</button>
										</span>
									</template>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		<div class="cb__pager" x-show="ledger.rows.length" x-cloak>
			<span class="mono muted"><span x-text="ledger.rows.length"></span> <?php esc_html_e( 'movimientos', 'keytill' ); ?></span>
			<div class="cb__pager-ctrl" x-show="ledgerPages() > 1">
				<button type="button" @click="ledgerGoPage(lpage - 1)" :disabled="lpage <= 1">&lsaquo;</button>
				<span class="mono"><span x-text="lpage"></span> / <span x-text="ledgerPages()"></span></span>
				<button type="button" @click="ledgerGoPage(lpage + 1)" :disabled="lpage >= ledgerPages()">&rsaquo;</button>
			</div>
		</div>
		</section>

		<!-- Modal: saldo inicial de la caja digital (primera vez) -->
		<div class="keytill-modal" x-show="seed.open" x-cloak @click.self="seed.open = false">
			<div class="keytill-modal__card">
				<span class="modal__title"><?php esc_html_e( 'SALDO INICIAL · CAJA DIGITAL', 'keytill' ); ?></span>
				<span class="mono modal__sub"><?php esc_html_e( 'Cuánto hay hoy en la cuenta bancaria (solo la primera vez).', 'keytill' ); ?></span>
				<label class="modal__label"><?php esc_html_e( 'Saldo inicial', 'keytill' ); ?>
					<input type="text" inputmode="decimal" x-model="seed.initial" class="modal__input mono cash-amount" placeholder="0" @keydown.enter="seedConfirm()" autocomplete="off" />
				</label>
				<div class="sheet-actions">
					<button type="button" class="prod__cancel" @click="seed.open = false"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<button type="button" class="prod__save" @click="seedConfirm()"><?php esc_html_e( 'Abrir', 'keytill' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Modal: movimiento (retiro / ingreso) -->
		<div class="keytill-modal" x-show="move.open" x-cloak @click.self="move.open = false">
			<div class="keytill-modal__card">
				<span class="modal__title mono" x-text="moveTitle()"></span>
				<label class="modal__label"><?php esc_html_e( 'Monto', 'keytill' ); ?>
					<input type="text" inputmode="decimal" x-model="move.amount" class="modal__input mono cash-amount" placeholder="0" @keydown.enter="submitMove()" autocomplete="off" />
				</label>
				<label class="modal__label"><?php esc_html_e( 'Concepto', 'keytill' ); ?>
					<input type="text" x-model="move.concept" class="modal__input" :placeholder="move.kind === 'ingreso' ? 'Ej. aporte, cambio' : 'Ej. pago proveedor'" @keydown.enter="submitMove()" autocomplete="off" />
				</label>
				<div class="pay__error" x-show="move.error" x-cloak x-text="move.error"></div>
				<div class="sheet-actions">
					<button type="button" class="prod__cancel" @click="move.open = false"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<button type="button" class="prod__save" :disabled="move.busy" @click="submitMove()"><?php esc_html_e( 'Registrar', 'keytill' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- ================= MODAL COBRO ================= -->
	<div class="keytill-modal" x-show="mode === 'pay'" x-cloak>
		<div class="keytill-modal__card pay">
			<div class="pay__head">
				<span class="pay__title">COBRO</span>
				<span class="mono pay__hint"
					x-text="payStage === 'seller' ? 'Elegí vendedor · 1·2·3… o flechas · ENTER' : (payStage === 'credit' ? 'Código de crédito · ENTER valida' : (payStage === 'amount' ? (isCash() ? 'Efectivo · tipeá lo recibido' : (chargeTotal() <= 0 ? 'Sin saldo a cobrar · ENTER confirma' : 'Enter para confirmar')) : (chargeTotal() <= 0 ? 'Bonificado · ENTER confirma' : 'Elegí 1·2·3·4 · C crédito')))"></span>
				<span class="mono pay__esc">ESC volver</span>
			</div>

			<!-- Paso: vendedor (1° si hay vendedores cargados) -->
			<div class="pay__stage-seller" x-show="payStage === 'seller'" x-cloak>
				<span class="pay__col-label"><?php esc_html_e( 'VENDEDOR', 'keytill' ); ?></span>
				<div class="pay__sellers">
					<template x-for="(s, i) in sellers" :key="s.id">
						<div class="pay__method" :class="{ 'is-on': s.id === sellerId }" @click="pickSeller(s.id)" role="button">
							<span class="mono pay__key" x-text="i < 9 ? (i + 1) : '·'"></span>
							<span class="pay__method-name" x-text="s.name"></span>
						</div>
					</template>
				</div>
				<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'continuar al medio de pago', 'keytill' ); ?></div>
				<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
					<button type="button" class="sheet-btn sheet-btn--ghost" @click="closeOverlay()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<button type="button" class="sheet-btn sheet-btn--primary" @click="pickSeller(sellerId)"><?php esc_html_e( 'Continuar', 'keytill' ); ?></button>
				</div>
			</div>

			<!-- Sub-etapa: ingreso de código de crédito -->
			<div class="pay__credit-entry" x-show="payStage === 'credit'" x-cloak>
				<span class="pay__col-label"><?php esc_html_e( 'CRÉDITO EN TIENDA', 'keytill' ); ?></span>
				<span class="mono modal__sub"><?php esc_html_e( 'Ingresá el código · ENTER valida · ESC vuelve', 'keytill' ); ?></span>
				<input type="text" x-ref="payCredit" x-model="creditCodeDraft" class="modal__input mono cash-amount" placeholder="HM-XXXX-XXXX" autocomplete="off" spellcheck="false" style="text-transform:uppercase" />
				<div class="pay__error" x-show="creditError" x-cloak x-text="creditError"></div>
				<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'validar crédito', 'keytill' ); ?></div>
				<div class="pay__credit-actions sheet-actions" x-show="$store.pos.touch" x-cloak>
					<button type="button" class="sheet-btn sheet-btn--ghost" @click="cancelCreditEntry()"><?php esc_html_e( 'Volver', 'keytill' ); ?></button>
					<button type="button" class="sheet-btn sheet-btn--primary" @click="validateCredit()" :disabled="busy"><?php esc_html_e( 'Validar crédito', 'keytill' ); ?></button>
				</div>
			</div>

			<!-- Etapas medio + monto -->
			<div class="pay__body" x-show="payStage === 'method' || payStage === 'amount'">
				<div class="pay__methods">
					<span class="pay__col-label">MEDIO DE PAGO</span>
					<template x-for="m in methods" :key="m.id">
						<div class="pay__method" :class="{ 'is-on': m.id === payMethod }" @click="pickMethod(m.id)" role="button">
							<span class="mono pay__key" x-text="m.key"></span>
							<span class="pay__method-name" x-text="m.name"></span>
						</div>
					</template>
					<div class="pay__method pay__method--credit" :class="{ 'is-on': !!creditApplied }" @click="openCreditEntry()" role="button">
						<span class="mono pay__key">C</span>
						<span class="pay__method-name"><?php esc_html_e( 'Crédito en tienda', 'keytill' ); ?></span>
					</div>
					<div class="pay__credit-applied" x-show="creditApplied" x-cloak>
						<span class="mono" x-text="creditApplied ? creditApplied.code : ''"></span>
						<span class="mono">− <span x-text="money(appliedCredit())"></span></span>
						<span class="mono muted pay__credit-clear"><?php esc_html_e( 'SUPR quita', 'keytill' ); ?></span>
					</div>
				</div>
				<div class="pay__amount">
					<div class="pay__row pay__row--muted" x-show="discount > 0" x-cloak>
						<span><?php esc_html_e( 'Subtotal', 'keytill' ); ?></span>
						<span class="mono" x-text="money(subtotal())"></span>
					</div>
					<div class="pay__row pay__row--muted" x-show="discount > 0" x-cloak>
						<span><?php esc_html_e( 'Descuento', 'keytill' ); ?> <span x-show="discountType === 'percent'" x-text="'(' + discount + '%)'"></span></span>
						<span class="mono">−<span x-text="money(discountAmount())"></span></span>
					</div>
					<div class="pay__row">
						<span class="pay__col-label">A COBRAR</span>
						<span class="mono pay__total" x-text="money(chargeTotal())"></span>
					</div>
					<div class="pay__row pay__row--credit" x-show="creditApplied" x-cloak>
						<span class="mono muted"><?php esc_html_e( 'Total', 'keytill' ); ?> <span x-text="money(total())"></span> · <?php esc_html_e( 'crédito', 'keytill' ); ?> −<span x-text="money(appliedCredit())"></span></span>
					</div>
					<div class="pay__pad">
						<span class="mono pay__pad-label"
							x-text="payStage === 'amount' ? (isCash() ? 'RECIBIDO' : 'IMPORTE') : 'ESPERANDO MEDIO'"></span>
						<span class="mono pay__pad-value"
							x-text="payStage === 'amount' ? (isCash() ? money(recibido()) : money(chargeTotal())) : '—'"></span>
					</div>
					<div class="pay__change" x-show="payStage === 'amount' && isCash()" :class="{ 'is-neg': vuelto() < 0 }" x-cloak>
						<span class="mono" x-text="vuelto() < 0 ? 'FALTAN' : 'VUELTO'"></span>
						<span class="mono pay__change-value" x-text="money(Math.abs(vuelto()))"></span>
					</div>
					<div class="pay__error" x-show="payError" x-cloak x-text="payError"></div>
					<!-- Keypad táctil para el efectivo recibido -->
					<div class="pay__keypad" x-show="$store.pos.touch && payStage === 'amount' && isCash()" x-cloak>
						<template x-for="d in ['1','2','3','4','5','6','7','8','9']" :key="d">
							<button type="button" class="pay__key-btn" @click="padPush(d)" x-text="d"></button>
						</template>
						<button type="button" class="pay__key-btn pay__key-btn--fn" @click="padClear()">C</button>
						<button type="button" class="pay__key-btn" @click="padPush('0')">0</button>
						<button type="button" class="pay__key-btn pay__key-btn--fn" @click="padBack()">⌫</button>
					</div>

					<div class="pay__enter" x-show="!$store.pos.touch">
						<kbd class="kbd-brand">ENTER</kbd>
						<span x-text="payStage === 'amount' ? (vuelto() < 0 ? 'falta efectivo' : 'confirmar venta') : (chargeTotal() <= 0 ? 'confirmar venta' : 'usar el medio resaltado')"></span>
					</div>

					<!-- Acciones táctiles del cobro -->
					<div class="pay__touch-actions sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="closeOverlay()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
						<button type="button" class="sheet-btn sheet-btn--primary" x-show="payStage === 'amount' || (payStage === 'method' && chargeTotal() <= 0)" @click="confirmSale()" :disabled="busy"><?php esc_html_e( 'Confirmar venta', 'keytill' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ================= MODAL CLIENTE ================= -->
	<div class="keytill-modal" x-show="mode === 'customer'" x-cloak>
		<div class="keytill-modal__card customer-modal">
			<span class="modal__title">CLIENTE</span>

			<!-- BUSCAR / SELECCIONAR -->
			<template x-if="custStage === 'search'">
				<div class="cust">
					<span class="mono modal__sub"><?php esc_html_e( 'Buscá por nombre, email o DNI · ↑↓ elegí · ENTER asigna · TAB crear nuevo · ESC cierra', 'keytill' ); ?></span>
					<input type="text" x-ref="custSearch" x-model="custQuery" @input.debounce.250ms="searchCustomers()" class="modal__input" placeholder="<?php esc_attr_e( 'Nombre / email / DNI', 'keytill' ); ?>" autocomplete="off" spellcheck="false" />
					<div class="cust__results" x-show="custResults.length">
						<template x-for="(c, i) in custResults" :key="c.id">
							<div class="cust__row" :class="{ 'is-sel': i === custSel }" @click="selectCustomer(c)" @mouseenter="custSel = i">
								<span class="cust__name" x-text="c.name || '<?php esc_attr_e( '(sin nombre)', 'keytill' ); ?>'"></span>
								<span class="mono muted cust__meta" x-text="[c.email, c.doc, c.iva].filter(Boolean).join(' · ')"></span>
							</div>
						</template>
					</div>
					<span class="mono muted cust__empty" x-show="custQuery && !custResults.length && !custBusy"><?php esc_html_e( 'Sin resultados · TAB para crear nuevo', 'keytill' ); ?></span>
					<div class="cust__foot">
						<span class="mono muted" x-show="customer"><?php esc_html_e( 'Actual:', 'keytill' ); ?> <b x-text="customer ? (customer.name || customer.doc || customer.email) : ''"></b> · <kbd>SUPR</kbd> <?php esc_html_e( 'quitar', 'keytill' ); ?></span>
						<button type="button" class="cust__link" @click="startCreateCustomer()"><kbd class="kbd-brand">TAB</kbd> <?php esc_html_e( 'crear nuevo', 'keytill' ); ?></button>
					</div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="closeOverlay()"><?php esc_html_e( 'Cerrar', 'keytill' ); ?></button>
						<button type="button" class="sheet-btn" x-show="customer" @click="detachCustomer()"><?php esc_html_e( 'Quitar cliente', 'keytill' ); ?></button>
					</div>
				</div>
			</template>

			<!-- CREAR NUEVO -->
			<template x-if="custStage === 'create'">
				<div class="cust">
					<span class="mono modal__sub"><?php esc_html_e( 'Nuevo cliente · al menos email o DNI · TAB entre campos · ENTER guarda · ESC vuelve', 'keytill' ); ?></span>
					<label class="modal__label"><?php esc_html_e( 'Nombre', 'keytill' ); ?>
						<input type="text" x-ref="custName" x-model="newCust.name" class="modal__input" autocomplete="off" />
					</label>
					<div class="modal__grid">
						<label class="modal__label"><?php esc_html_e( 'Email', 'keytill' ); ?>
							<input type="email" x-model="newCust.email" class="modal__input mono" placeholder="—" autocomplete="off" spellcheck="false" />
						</label>
						<label class="modal__label"><?php esc_html_e( 'DNI / CUIT', 'keytill' ); ?>
							<input type="text" x-model="newCust.doc" class="modal__input mono" placeholder="—" autocomplete="off" />
						</label>
					</div>
					<div class="modal__grid">
						<label class="modal__label"><?php esc_html_e( 'Teléfono', 'keytill' ); ?>
							<input type="tel" x-model="newCust.phone" class="modal__input mono" placeholder="—" autocomplete="off" />
						</label>
						<label class="modal__label"><?php esc_html_e( 'Fecha de nacimiento', 'keytill' ); ?>
							<input type="date" x-model="newCust.birthdate" class="modal__input mono" />
						</label>
					</div>
					<label class="modal__label"><?php esc_html_e( 'Condición fiscal', 'keytill' ); ?>
						<select x-model="newCust.iva" class="modal__input">
							<template x-for="opt in fiscalOptions" :key="opt">
								<option :value="opt" x-text="opt"></option>
							</template>
						</select>
					</label>
					<span class="cust__error mono" x-show="custError" x-text="custError"></span>
					<div class="pay__enter" x-show="!$store.pos.touch">
						<kbd class="kbd-brand">ENTER</kbd>
						<span><?php esc_html_e( 'guardar y asignar', 'keytill' ); ?></span>
					</div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="backToSearch()"><?php esc_html_e( 'Volver', 'keytill' ); ?></button>
						<button type="button" class="sheet-btn sheet-btn--primary" @click="submitCreateCustomer()" :disabled="custBusy"><?php esc_html_e( 'Guardar y asignar', 'keytill' ); ?></button>
					</div>
				</div>
			</template>

			<!-- CONFLICTO (ya existe) -->
			<template x-if="custStage === 'conflict'">
				<div class="cust">
					<span class="mono modal__sub cust__error" x-text="custError"></span>
					<div class="cust__row is-sel">
						<span class="cust__name" x-text="custConflict ? (custConflict.name || '<?php esc_attr_e( '(sin nombre)', 'keytill' ); ?>') : ''"></span>
						<span class="mono muted cust__meta" x-text="custConflict ? [custConflict.email, custConflict.doc, custConflict.iva].filter(Boolean).join(' · ') : ''"></span>
					</div>
					<div class="pay__enter" x-show="!$store.pos.touch">
						<kbd class="kbd-brand">ENTER</kbd>
						<span><?php esc_html_e( 'usar este cliente', 'keytill' ); ?></span>
						<span class="muted">·</span>
						<kbd>ESC</kbd>
						<span><?php esc_html_e( 'volver', 'keytill' ); ?></span>
					</div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="backToCreate()"><?php esc_html_e( 'Volver', 'keytill' ); ?></button>
						<button type="button" class="sheet-btn sheet-btn--primary" @click="useConflictCustomer()"><?php esc_html_e( 'Usar este cliente', 'keytill' ); ?></button>
					</div>
				</div>
			</template>
		</div>
	</div>

	<!-- ================= MODAL GIFT CARD (monto) ================= -->
	<div class="keytill-modal" x-show="mode === 'giftcard'" x-cloak>
		<div class="keytill-modal__card cash-modal">
			<span class="modal__title">GIFT CARD</span>
			<span class="mono modal__sub" x-text="giftPrompt ? giftPrompt.product.name : ''"></span>
			<template x-if="giftPrompt && giftPrompt.denoms && giftPrompt.denoms.length">
				<div class="gc-denoms">
					<template x-for="d in giftPrompt.denoms" :key="d">
						<button type="button" class="gc-denom" tabindex="-1" @click="pickGiftDenom(d)" x-text="money(d)"></button>
					</template>
				</div>
			</template>
			<label class="modal__label"><?php esc_html_e( 'Monto (o escribilo)', 'keytill' ); ?>
				<input type="text" inputmode="decimal" x-ref="giftAmount" x-model="giftAmountDraft" class="modal__input mono cash-amount" placeholder="0" autocomplete="off" />
			</label>
			<div class="pay__enter" x-show="!$store.pos.touch">
				<kbd class="kbd-brand">ENTER</kbd>
				<span><?php esc_html_e( 'agregar al ticket · ESC cancela', 'keytill' ); ?></span>
			</div>
			<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
				<button type="button" class="sheet-btn sheet-btn--ghost" @click="cancelGiftPrompt()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
				<button type="button" class="sheet-btn sheet-btn--primary" @click="confirmGiftPrompt()"><?php esc_html_e( 'Agregar', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ================= MODAL GIFT CARDS EMITIDAS ================= -->
	<div class="keytill-modal" x-show="mode === 'saleresult'" x-cloak>
		<div class="keytill-modal__card saleresult-modal">
			<span class="modal__title"><?php esc_html_e( 'VENTA REALIZADA', 'keytill' ); ?></span>
			<span class="mono modal__sub">
				<?php esc_html_e( 'Pedido', 'keytill' ); ?>
				<b x-text="saleResult ? ('#' + saleResult.number) : ''"></b>
				· <span x-text="saleResult ? saleResult.methodName : ''"></span>
			</span>

			<div class="saleresult__totals">
				<div class="saleresult__row">
					<span><?php esc_html_e( 'Total', 'keytill' ); ?></span>
					<span class="mono" x-text="saleResult ? money(saleResult.total) : ''"></span>
				</div>
				<template x-if="saleResult && saleResult.creditUsed > 0">
					<div class="saleresult__row">
						<span><?php esc_html_e( 'Crédito', 'keytill' ); ?></span>
						<span class="mono" x-text="money(saleResult.creditUsed)"></span>
					</div>
				</template>
				<template x-if="saleResult && saleResult.change > 0">
					<div class="saleresult__row saleresult__row--change">
						<span><?php esc_html_e( 'Vuelto', 'keytill' ); ?></span>
						<span class="mono" x-text="money(saleResult.change)"></span>
					</div>
				</template>
			</div>

			<template x-if="saleResult && saleResult.giftcards.length">
				<div class="giftresult__list">
					<span class="mono modal__sub"><?php esc_html_e( 'Gift cards emitidas — entregá los códigos', 'keytill' ); ?></span>
					<template x-for="(g, i) in saleResult.giftcards" :key="i">
						<div class="giftresult__item">
							<span class="mono giftresult__code" x-text="g.code"></span>
							<span class="mono giftresult__amount" x-text="money(g.amount)"></span>
						</div>
					</template>
				</div>
			</template>

			<div class="saleresult__actions">
				<button type="button" class="saleresult__print" @click="printSale()">
					<kbd class="kbd-brand">P</kbd>
					<span><?php esc_html_e( 'Imprimir pedido', 'keytill' ); ?></span>
				</button>
				<button type="button" class="saleresult__print" x-show="saleResult && saleResult.giftUrl" x-cloak @click="printGift()">
					<kbd class="kbd-brand">C</kbd>
					<span><?php esc_html_e( 'Ticket de cambio', 'keytill' ); ?></span>
				</button>
				<div class="pay__enter" x-show="!$store.pos.touch">
					<kbd class="kbd-brand">ENTER</kbd>
					<span><?php esc_html_e( 'continuar', 'keytill' ); ?></span>
				</div>
				<button type="button" class="sheet-btn sheet-btn--primary" x-show="$store.pos.touch" x-cloak @click="finishSaleResult()"><?php esc_html_e( 'Continuar', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ================= MODAL ESCÁNER (cámara) ================= -->
	<div class="keytill-modal" x-show="scanOpen" x-cloak @click.self="closeScanner()">
		<div class="keytill-modal__card scan-modal">
			<span class="modal__title"><?php esc_html_e( 'ESCANEAR CÓDIGO', 'keytill' ); ?></span>

			<!-- error de cámara -->
			<div class="scan__error" x-show="scanError" x-cloak x-text="scanError"></div>

			<!-- ETAPA: escaneando (cámara en vivo) -->
			<div x-show="!scanError && scanStage === 'scanning'" x-cloak>
				<span class="mono modal__sub"><?php esc_html_e( 'Apuntá la cámara al código de barras', 'keytill' ); ?></span>
				<div class="scan__stage">
					<video x-ref="scanVideo" class="scan__video" playsinline muted></video>
					<div class="scan__reticle"></div>
					<div class="scan__loading mono" x-show="scanBusy" x-cloak><?php esc_html_e( 'Abriendo cámara…', 'keytill' ); ?></div>
				</div>
			</div>

			<!-- ETAPA: confirmar producto -->
			<div class="scan__result" x-show="scanStage === 'confirm'" x-cloak>
				<span class="mono modal__sub"><?php esc_html_e( '¿Agregar este producto al carrito?', 'keytill' ); ?></span>
				<div class="scan__prod">
					<template x-if="scanMatch && scanMatch.image"><img class="scan__prodimg" :src="scanMatch.image" alt="" /></template>
					<div class="scan__prodinfo">
						<span class="scan__prodname" x-text="scanMatchLabel()"></span>
						<span class="mono scan__prodprice" x-text="scanMatch ? money(scanMatch.price) : ''"></span>
						<span class="mono muted scan__prodcode" x-text="scanCode"></span>
					</div>
				</div>
			</div>

			<!-- ETAPA: código no reconocido -->
			<div class="scan__result" x-show="scanStage === 'notfound'" x-cloak>
				<div class="scan__msg scan__msg--warn">
					<span class="scan__prodname"><?php esc_html_e( 'Código no reconocido', 'keytill' ); ?></span>
					<span class="mono" x-text="scanCode"></span>
					<span class="mono muted"><?php esc_html_e( 'No hay ningún producto con ese código de barras.', 'keytill' ); ?></span>
				</div>
			</div>

			<!-- ETAPA: agregado -->
			<div class="scan__result" x-show="scanStage === 'added'" x-cloak>
				<div class="scan__msg scan__msg--ok">
					<span class="scan__ok">✓ <?php esc_html_e( 'Agregado al carrito', 'keytill' ); ?></span>
					<span class="scan__prodname" x-text="scanMatchLabel()"></span>
				</div>
			</div>

			<!-- acciones según etapa -->
			<div class="sheet-actions">
				<template x-if="scanError">
					<button type="button" class="sheet-btn sheet-btn--primary" @click="scanAgain()"><?php esc_html_e( 'Reintentar', 'keytill' ); ?></button>
				</template>
				<template x-if="scanStage === 'confirm'">
					<button type="button" class="sheet-btn sheet-btn--primary" @click="confirmScan()"><?php esc_html_e( 'Agregar al carrito', 'keytill' ); ?></button>
				</template>
				<template x-if="scanStage === 'confirm'">
					<button type="button" class="sheet-btn sheet-btn--ghost" @click="scanAgain()"><?php esc_html_e( 'Reintentar', 'keytill' ); ?></button>
				</template>
				<template x-if="!scanError && (scanStage === 'added' || scanStage === 'notfound')">
					<button type="button" class="sheet-btn sheet-btn--primary" @click="scanAgain()"><?php esc_html_e( 'Escanear otro', 'keytill' ); ?></button>
				</template>
				<button type="button" class="sheet-btn sheet-btn--ghost" @click="closeScanner()"><?php esc_html_e( 'Cerrar', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ================= MODAL DESCUENTO ================= -->
	<div class="keytill-modal" x-show="mode === 'discount'" x-cloak>
		<div class="keytill-modal__card discount-modal">

			<!-- PASO 1: alcance -->
			<template x-if="discStage === 'scope'">
				<div class="disc-stage">
					<span class="modal__title"><?php esc_html_e( 'DESCUENTO', 'keytill' ); ?></span>
					<span class="mono modal__sub"><?php esc_html_e( '¿A qué se aplica? · 1 toda la venta · 2 una prenda · ESC cancela', 'keytill' ); ?></span>
					<div class="disc-scope">
						<button type="button" class="disc-scope__btn" @click="pickDiscScope('global')">
							<span class="mono disc-scope__key">1</span>
							<span><?php esc_html_e( 'Toda la venta', 'keytill' ); ?></span>
						</button>
						<button type="button" class="disc-scope__btn" @click="pickDiscScope('line')" :disabled="!discountableLines().length">
							<span class="mono disc-scope__key">2</span>
							<span><?php esc_html_e( 'Una prenda', 'keytill' ); ?></span>
						</button>
					</div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="closeOverlay()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					</div>
				</div>
			</template>

			<!-- PASO 2: elegir prenda -->
			<template x-if="discStage === 'line'">
				<div class="disc-stage">
					<span class="modal__title"><?php esc_html_e( '¿QUÉ PRENDA?', 'keytill' ); ?></span>
					<span class="mono modal__sub"><?php esc_html_e( 'Elegí la prenda · número o toque · ESC vuelve', 'keytill' ); ?></span>
					<div class="disc-lines">
						<template x-for="(row, k) in discountableLines()" :key="row.i">
							<button type="button" class="disc-line__btn" @click="pickDiscLine(row.i)">
								<span class="mono disc-line__key" x-text="k < 9 ? (k + 1) : '·'"></span>
								<span class="disc-line__name" x-text="row.l.name + (row.l.variation ? ' · ' + row.l.variation : '')"></span>
								<span class="mono disc-line__sub" x-text="money(lineNet(row.l))"></span>
							</button>
						</template>
					</div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="discStage = 'scope'"><?php esc_html_e( 'Volver', 'keytill' ); ?></button>
					</div>
				</div>
			</template>

			<!-- PASO 3: valor -->
			<template x-if="discStage === 'value'">
				<div class="disc-stage">
					<span class="modal__title" x-text="discLineIdx !== null ? '<?php echo esc_js( __( 'DESCUENTO POR PRENDA', 'keytill' ) ); ?>' : '<?php echo esc_js( __( 'DESCUENTO (TODA LA VENTA)', 'keytill' ) ); ?>'"></span>
					<span class="mono modal__sub" x-show="discLineIdx !== null" x-cloak x-text="discountTargetName()"></span>
					<span class="mono modal__sub"><?php esc_html_e( 'TAB cambia % / monto · ENTER aplica · ESC cancela', 'keytill' ); ?></span>
					<div class="discount__types">
						<button type="button" class="discount__type" :class="{ 'is-active': discountTypeDraft === 'percent' }" @click="setDiscountType('percent')"><?php esc_html_e( 'Porcentaje %', 'keytill' ); ?></button>
						<button type="button" class="discount__type" :class="{ 'is-active': discountTypeDraft === 'fixed' }" @click="setDiscountType('fixed')"><?php esc_html_e( 'Monto fijo $', 'keytill' ); ?></button>
					</div>
					<div class="discount__display">
						<span class="mono discount__pct" x-show="discountTypeDraft === 'fixed'">$</span>
						<span class="mono discount__value" x-text="discountDraft || '0'"></span>
						<span class="mono discount__pct" x-show="discountTypeDraft === 'percent'">%</span>
					</div>
					<div class="discount__preview">
						<span x-text="discLineIdx !== null ? '<?php echo esc_js( __( 'Nuevo subtotal de la prenda', 'keytill' ) ); ?>' : '<?php echo esc_js( __( 'Nuevo total', 'keytill' ) ); ?>'"></span>
						<span class="mono discount__new" x-text="discountPreview()"></span>
					</div>
					<div class="pay__keypad" x-show="$store.pos.touch" x-cloak>
						<template x-for="d in ['1','2','3','4','5','6','7','8','9']" :key="d">
							<button type="button" class="pay__key-btn" @click="discPush(d)" x-text="d"></button>
						</template>
						<button type="button" class="pay__key-btn pay__key-btn--fn" @click="discountDraft = ''">C</button>
						<button type="button" class="pay__key-btn" @click="discPush('0')">0</button>
						<button type="button" class="pay__key-btn pay__key-btn--fn" @click="discBack()">⌫</button>
					</div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="closeOverlay()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
						<button type="button" class="sheet-btn sheet-btn--primary" @click="applyDiscount()"><?php esc_html_e( 'Aplicar', 'keytill' ); ?></button>
					</div>
				</div>
			</template>
		</div>
	</div>

	<!-- ================= DEVOLUCIÓN / CAMBIO ================= -->
	<div class="keytill-modal" x-show="mode === 'return'" x-cloak>
		<div class="keytill-modal__card return-modal">
			<div class="pay__head">
				<span class="pay__title">DEVOLUCIÓN</span>
				<span class="mono pay__hint"><?php esc_html_e( 'refund de Woo · reingreso de stock', 'keytill' ); ?></span>
				<span class="mono pay__esc">ESC volver</span>
			</div>

			<!-- Etapa: buscar venta -->
			<div class="return-body" x-show="returnStage === 'lookup'">
				<span class="mono modal__sub"><?php esc_html_e( 'Número de venta (pedido) · ENTER busca', 'keytill' ); ?></span>
				<label class="modal__label"><?php esc_html_e( 'N° de venta', 'keytill' ); ?>
					<input type="text" inputmode="numeric" x-ref="returnLookup" x-model="returnNumber" class="modal__input mono cash-amount" placeholder="0" autocomplete="off" />
				</label>
				<div class="pay__error" x-show="returnError" x-cloak x-text="returnError"></div>
				<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'buscar venta', 'keytill' ); ?></div>
				<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
					<button type="button" class="sheet-btn sheet-btn--ghost" @click="cancelReturn()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
					<button type="button" class="sheet-btn sheet-btn--primary" @click="lookupOrder()" :disabled="busy"><?php esc_html_e( 'Buscar venta', 'keytill' ); ?></button>
				</div>
			</div>

			<!-- Etapa: elegir cantidades -->
			<div class="return-body" x-show="returnStage === 'select'">
				<div class="return-info">
					<span>Venta <span class="mono" x-text="returnData ? '#' + returnData.number : ''"></span> · <span class="mono" x-text="returnData ? returnData.payment_title : ''"></span></span>
					<span class="mono muted" x-text="returnData ? returnData.date : ''"></span>
				</div>
				<div class="return-hint mono"><?php esc_html_e( '↑↓ elegir línea · + − cantidad · C/E/T medio de reintegro · ENTER procesa', 'keytill' ); ?></div>
				<div class="return-lines">
					<template x-for="(it, i) in (returnData ? returnData.items : [])" :key="it.item_id">
						<div class="return-line" :class="{ 'is-sel': i === returnSel }" @click="returnSel = i">
							<span class="return-line__name">
								<span x-text="it.name"></span>
								<span class="mono muted" x-text="it.sku"></span>
							</span>
							<span class="mono return-line__ref"><?php esc_html_e( 'devolvibles', 'keytill' ); ?> <span x-text="it.qty_refundable"></span></span>
							<span class="mono return-line__unit" x-text="money(it.unit_price)"></span>
							<span class="mono return-line__qty" :class="{ 'is-active': it.rqty > 0 }" x-text="it.rqty + ' / ' + it.qty_refundable"></span>
							<div class="line__ctrl" x-show="$store.pos.touch" x-cloak>
								<button type="button" class="line__btn" @click.stop="returnDec(i)" aria-label="<?php esc_attr_e( 'Restar', 'keytill' ); ?>">−</button>
								<button type="button" class="line__btn" @click.stop="returnInc(i)" aria-label="<?php esc_attr_e( 'Sumar', 'keytill' ); ?>">+</button>
							</div>
						</div>
					</template>
				</div>
				<div class="return-foot">
					<div class="return-total">
						<span class="mono muted"><span x-text="returnUnits()"></span> u. a devolver</span>
						<span class="return-total__amt mono"><span x-text="money(returnTotal())"></span></span>
					</div>
					<div class="return-method" x-show="returnUnits() > 0" x-cloak>
						<span class="return-method__label"><?php esc_html_e( 'REINTEGRO', 'keytill' ); ?></span>
						<div class="return-method__opts">
							<button type="button" class="return-method__opt" :class="{ 'is-on': returnMethod === 'credito' }" @click="setReturnMethod('credito')" tabindex="-1">
								<span class="mono">C</span> <?php esc_html_e( 'Crédito en tienda', 'keytill' ); ?>
							</button>
							<button type="button" class="return-method__opt" :class="{ 'is-on': returnMethod === 'efectivo' }" @click="setReturnMethod('efectivo')" tabindex="-1">
								<span class="mono">E</span> <?php esc_html_e( 'Efectivo', 'keytill' ); ?>
							</button>
							<button type="button" class="return-method__opt" :class="{ 'is-on': returnMethod === 'transferencia' }" @click="setReturnMethod('transferencia')" tabindex="-1">
								<span class="mono">T</span> <?php esc_html_e( 'Transferencia', 'keytill' ); ?>
							</button>
						</div>
					</div>
					<div class="return-cash" x-show="returnMethod === 'efectivo' && returnUnits() > 0" x-cloak>
						<?php esc_html_e( 'Efectivo a devolver del cajón', 'keytill' ); ?>
					</div>
					<div class="return-credit-note" x-show="returnMethod === 'credito' && returnUnits() > 0" x-cloak>
						<?php esc_html_e( 'Se emitirá un crédito en tienda por', 'keytill' ); ?> <span class="mono" x-text="money(returnTotal())"></span>
					</div>
					<div class="return-credit-note" x-show="returnMethod === 'transferencia' && returnUnits() > 0" x-cloak>
						<?php esc_html_e( 'Se devuelve por transferencia (resta de la caja digital)', 'keytill' ); ?> <span class="mono" x-text="money(returnTotal())"></span>
					</div>
					<div class="pay__error" x-show="returnError" x-cloak x-text="returnError"></div>
					<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'procesar devolución', 'keytill' ); ?></div>
					<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
						<button type="button" class="sheet-btn sheet-btn--ghost" @click="cancelReturn()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
						<button type="button" class="sheet-btn sheet-btn--primary" @click="confirmReturn()" :disabled="busy"><?php esc_html_e( 'Procesar devolución', 'keytill' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Etapa: resumen -->
			<div class="return-body return-done" x-show="returnStage === 'done'" x-cloak>
				<span class="cash-done__title"><?php esc_html_e( 'DEVOLUCIÓN OK', 'keytill' ); ?></span>
				<div class="cash-line"><span><?php esc_html_e( 'Venta', 'keytill' ); ?></span><span class="mono" x-text="returnResult ? '#' + returnResult.order_number : ''"></span></div>
				<div class="cash-line"><span><?php esc_html_e( 'Devuelto', 'keytill' ); ?></span><span class="mono" x-text="returnResult ? money(returnResult.amount) : ''"></span></div>

				<!-- Reintegro en efectivo -->
				<div class="cash-diff cash-diff--big is-neg" x-show="returnResult && returnResult.cash_back > 0" x-cloak>
					<span><?php esc_html_e( 'EFECTIVO A DEVOLVER', 'keytill' ); ?></span>
					<span class="mono" x-text="returnResult ? money(returnResult.cash_back) : ''"></span>
				</div>
					<div class="cash-diff cash-diff--big is-neg" x-show="returnResult && returnResult.digital_back > 0" x-cloak>
						<span>A DEVOLVER POR TRANSFERENCIA</span>
						<span class="mono" x-text="returnResult ? money(returnResult.digital_back) : ''"></span>
					</div>

				<!-- Crédito en tienda emitido -->
				<div class="return-credit-result" x-show="returnResult && returnResult.credit_code" x-cloak>
					<span class="return-credit-result__label"><?php esc_html_e( 'CRÉDITO EN TIENDA', 'keytill' ); ?></span>
					<span class="mono return-credit-result__code" x-text="returnResult ? returnResult.credit_code : ''"></span>
					<span class="mono return-credit-result__amt" x-text="returnResult ? money(returnResult.credit_amount) : ''"></span>
					<span class="mono muted" x-show="returnResult && returnResult.credit_expires"><?php esc_html_e( 'vence', 'keytill' ); ?> <span x-text="returnResult ? returnResult.credit_expires : ''"></span></span>
					<button type="button" class="return-credit-result__print" x-show="returnResult && returnResult.voucher_url" x-cloak
						@click="window.open(returnResult.voucher_url, '_blank')">🖨 <?php esc_html_e( 'Imprimir voucher', 'keytill' ); ?></button>
				</div>

				<div class="return-restock mono"><?php esc_html_e( 'Stock reingresado ✓', 'keytill' ); ?></div>
				<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'listo · para un cambio, escaneá los productos nuevos', 'keytill' ); ?></div>
					<button type="button" class="sheet-btn sheet-btn--primary" x-show="$store.pos.touch" x-cloak @click="finishReturn()"><?php esc_html_e( 'Listo', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ============= FALTANTE DE STOCK: ELEGIR ORIGEN ============= -->
	<div class="keytill-modal" x-show="mode === 'stockfix'" x-cloak>
		<div class="keytill-modal__card stockfix">
			<span class="modal__title"><?php esc_html_e( 'SIN STOCK EN TU SUCURSAL', 'keytill' ); ?></span>
			<span class="mono modal__sub"><?php esc_html_e( 'Elegí de dónde sale cada prenda · ENTER confirma · ESC cancela', 'keytill' ); ?></span>
			<div class="stockfix__list">
				<template x-for="(it, idx) in stockFix.items" :key="it.product_id">
					<div class="stockfix__item">
						<div class="stockfix__head">
							<span class="stockfix__name" x-text="it.name"></span>
							<span class="mono muted" x-text="'sin stock en ' + it.branch_name + ' (hay ' + it.have + ', pedís ' + it.need + ')'"></span>
						</div>
						<div class="stockfix__opts">
							<button type="button" class="stockfix__opt" :class="{ 'is-on': stockChoiceIs(idx, 'force') }"
								@click="pickStockChoice(idx, { stock_force: true }, 'force')">
								<?php esc_html_e( 'Vender igual desde', 'keytill' ); ?> <b x-text="it.branch_name"></b>
								<small><?php esc_html_e( '(queda faltante)', 'keytill' ); ?></small>
							</button>
							<template x-for="o in it.options" :key="o.branch">
								<button type="button" class="stockfix__opt" :class="{ 'is-on': stockChoiceIs(idx, o.branch) }"
									@click="pickStockChoice(idx, { stock_branch: o.branch }, o.branch)">
									<?php esc_html_e( 'Descontar de', 'keytill' ); ?> <b x-text="o.name"></b> <small x-text="'(' + o.qty + ')'"></small>
								</button>
							</template>
						</div>
					</div>
				</template>
			</div>
			<div class="prod__modal-actions">
				<button type="button" class="prod__cancel" @click="cancelStockFix()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
				<span class="prod__modal-spacer"></span>
				<button type="button" class="prod__save" :disabled="!stockFixReady()" @click="confirmStockFix()"><?php esc_html_e( 'Confirmar y cobrar', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ================= APERTURA DE CAJA ================= -->
	<div class="keytill-modal keytill-modal--gate" x-show="mode === 'open'" x-cloak>
		<div class="keytill-modal__card cash-modal">
			<span class="modal__title">ABRIR CAJA</span>
			<!-- Motivo contextual: por qué se pide la apertura ahora. -->
			<span class="mono modal__sub" x-show="openCtx === 'pay'" x-cloak><?php esc_html_e( 'Para cobrar necesitás una caja abierta. Ingresá el fondo inicial · ENTER abre la caja', 'keytill' ); ?></span>
			<span class="mono modal__sub" x-show="openCtx === 'return'" x-cloak><?php esc_html_e( 'Para hacer una devolución necesitás una caja abierta. Ingresá el fondo inicial · ENTER abre la caja', 'keytill' ); ?></span>
			<span class="mono modal__sub" x-show="!openCtx" x-cloak><?php esc_html_e( 'Ingresá el fondo inicial de efectivo · ENTER abre la caja', 'keytill' ); ?></span>
			<!-- Sucursal de la caja: de dónde sale/entra el stock por sucursal. -->
			<label class="modal__label" x-show="branches.length" x-cloak><?php esc_html_e( 'Sucursal de la caja', 'keytill' ); ?>
				<select x-model="openBranch" class="modal__input">
					<template x-for="b in branches" :key="b.id"><option :value="b.id" x-text="b.name"></option></template>
				</select>
			</label>
			<label class="modal__label"><?php esc_html_e( 'Fondo inicial', 'keytill' ); ?>
				<input type="text" inputmode="decimal" x-ref="cashOpen" x-model="openFloat" class="modal__input mono cash-amount" placeholder="0" autocomplete="off" />
			</label>
			<div class="pay__error" x-show="cashError" x-cloak x-text="cashError"></div>
			<button type="button" class="sheet-btn sheet-btn--primary" x-show="$store.pos.touch" x-cloak @click="openCash()" :disabled="busy"><?php esc_html_e( 'Abrir caja', 'keytill' ); ?></button>
			<button type="button" class="sheet-btn sheet-btn--ghost" x-show="$store.pos.touch" x-cloak @click="dismissOpenCash()"><?php esc_html_e( 'Seguir sin abrir caja', 'keytill' ); ?></button>
			<div class="cash-modal__foot">
				<span class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'abrir caja', 'keytill' ); ?> · <kbd>ESC</kbd> <?php esc_html_e( 'seguir sin abrir', 'keytill' ); ?></span>
				<span class="cash-modal__exits">
					<?php if ( $keytill_can_manage ) : ?>
						<a class="keytill-nav__exit" href="<?php echo esc_url( admin_url() ); ?>" tabindex="-1"><?php esc_html_e( 'Panel', 'keytill' ); ?> ↗</a>
					<?php endif; ?>
					<a class="keytill-nav__exit" href="<?php echo esc_url( $keytill_logout_url ); ?>" tabindex="-1"><?php esc_html_e( 'Cerrar sesión', 'keytill' ); ?></a>
				</span>
			</div>
		</div>
	</div>

	<!-- ================= RETIRO DE EFECTIVO ================= -->
	<div class="keytill-modal" x-show="mode === 'withdraw'" x-cloak>
		<div class="keytill-modal__card cash-modal">
			<span class="modal__title" x-text="withdrawAccount === 'digital' ? 'RETIRO · CAJA DIGITAL' : 'RETIRO · EFECTIVO'"></span>
			<span class="mono modal__sub"><?php esc_html_e( 'Monto y motivo · TAB entre campos · ENTER confirma · ESC cancela', 'keytill' ); ?></span>
			<div class="return-method" x-show="$store.pos.canManage" x-cloak>
				<span class="return-method__label">ORIGEN</span>
				<div class="return-method__opts">
					<button type="button" class="return-method__opt" :class="{ 'is-on': withdrawAccount === 'efectivo' }" @click="setWithdrawAccount('efectivo')" tabindex="-1">Caja física</button>
					<button type="button" class="return-method__opt" :class="{ 'is-on': withdrawAccount === 'digital' }" @click="setWithdrawAccount('digital')" tabindex="-1">Caja digital</button>
				</div>
			</div>
			<label class="modal__label"><?php esc_html_e( 'Monto', 'keytill' ); ?>
				<input type="text" inputmode="decimal" x-ref="cashWithdraw" x-model="withdrawAmount" class="modal__input mono cash-amount" placeholder="0" autocomplete="off" />
			</label>
			<label class="modal__label"><?php esc_html_e( 'Motivo', 'keytill' ); ?>
				<input type="text" x-model="withdrawReason" class="modal__input" placeholder="<?php esc_attr_e( 'ej. pago a proveedor', 'keytill' ); ?>" autocomplete="off" />
			</label>
			<div class="pay__error" x-show="cashError" x-cloak x-text="cashError"></div>
			<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'registrar retiro', 'keytill' ); ?></div>
			<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
				<button type="button" class="sheet-btn sheet-btn--ghost" @click="cancelCash()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
				<button type="button" class="sheet-btn sheet-btn--primary" @click="confirmWithdraw()" :disabled="busy"><?php esc_html_e( 'Registrar retiro', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ================= CIERRE DE CAJA (ARQUEO) ================= -->
	<div class="keytill-modal" x-show="mode === 'close'" x-cloak>
		<div class="keytill-modal__card cash-close">
			<div class="pay__head">
				<span class="pay__title">CIERRE DE CAJA</span>
				<span class="mono pay__hint"><?php esc_html_e( 'arqueo de efectivo', 'keytill' ); ?></span>
				<span class="mono pay__esc">ESC volver</span>
			</div>

			<!-- Etapa: formulario de arqueo -->
			<div class="cash-close__body" x-show="closeStage === 'form'">
				<div class="cash-close__col">
					<span class="pay__col-label"><?php esc_html_e( 'DESGLOSE POR MEDIO', 'keytill' ); ?></span>
					<template x-for="b in (session ? session.breakdown.filter(x => x.count > 0) : [])" :key="b.id">
						<div class="cash-brk">
							<span x-text="b.label"></span>
							<span class="mono"><span x-text="money(b.total)"></span> · <span x-text="b.count"></span></span>
						</div>
					</template>
					<div class="cash-brk muted" x-show="session && !session.breakdown.some(x => x.count > 0)">
						<span><?php esc_html_e( 'Sin ventas en esta sesión', 'keytill' ); ?></span>
					</div>
					<div class="cash-brk cash-brk--sum">
						<span><?php esc_html_e( 'Ventas', 'keytill' ); ?></span>
						<span class="mono" x-text="session ? money(session.sales_total) : ''"></span>
					</div>

					<template x-if="session && session.by_cashier && session.by_cashier.length">
						<div class="cash-cashiers">
							<span class="pay__col-label"><?php esc_html_e( 'VENTAS POR CAJERO', 'keytill' ); ?></span>
							<template x-for="c in session.by_cashier" :key="c.id">
								<div class="cash-brk">
									<span x-text="c.name"></span>
									<span class="mono"><span x-text="money(c.total)"></span> · <span x-text="c.count"></span></span>
								</div>
							</template>
						</div>
					</template>
				</div>
				<div class="cash-close__col">
					<div class="cash-line"><span><?php esc_html_e( 'Fondo inicial', 'keytill' ); ?></span><span class="mono" x-text="session ? money(session.opening_float) : ''"></span></div>
					<div class="cash-line"><span>+ <?php esc_html_e( 'Ventas en efectivo', 'keytill' ); ?></span><span class="mono" x-text="session ? money(session.cash_sales) : ''"></span></div>
					<div class="cash-line"><span>− <?php esc_html_e( 'Retiros', 'keytill' ); ?></span><span class="mono" x-text="session ? money(session.withdrawals_total) : ''"></span></div>
					<div class="cash-line cash-line--exp"><span><?php esc_html_e( 'Efectivo esperado', 'keytill' ); ?></span><span class="mono" x-text="session ? money(session.expected_cash) : ''"></span></div>
					<label class="modal__label"><?php esc_html_e( 'Efectivo contado', 'keytill' ); ?>
						<input type="text" inputmode="decimal" x-ref="cashClose" x-model="countedCash" class="modal__input mono cash-amount" placeholder="0" autocomplete="off" />
					</label>
					<div class="cash-diff" :class="diffClass(diffPreview())">
						<span x-text="diffLabel(diffPreview())"></span>
						<span class="mono" x-text="money(Math.abs(diffPreview()))"></span>
					</div>
					<div class="pay__error" x-show="cashError" x-cloak x-text="cashError"></div>
					<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'cerrar caja', 'keytill' ); ?></div>
						<div class="sheet-actions" x-show="$store.pos.touch" x-cloak>
							<button type="button" class="sheet-btn sheet-btn--ghost" @click="cancelCash()"><?php esc_html_e( 'Cancelar', 'keytill' ); ?></button>
							<button type="button" class="sheet-btn sheet-btn--primary" @click="confirmClose()" :disabled="busy"><?php esc_html_e( 'Cerrar caja', 'keytill' ); ?></button>
						</div>
				</div>
			</div>

			<!-- Etapa: resumen del cierre -->
			<div class="cash-close__done" x-show="closeStage === 'done'" x-cloak>
				<span class="cash-done__title"><?php esc_html_e( 'CAJA CERRADA', 'keytill' ); ?></span>
				<div class="cash-line"><span><?php esc_html_e( 'Efectivo esperado', 'keytill' ); ?></span><span class="mono" x-text="closeResult ? money(closeResult.expected_cash) : ''"></span></div>
				<div class="cash-line"><span><?php esc_html_e( 'Efectivo contado', 'keytill' ); ?></span><span class="mono" x-text="closeResult ? money(closeResult.counted_cash) : ''"></span></div>
				<div class="cash-diff cash-diff--big" :class="closeResult ? diffClass(closeResult.difference) : ''">
					<span x-text="closeResult ? diffLabel(closeResult.difference) : ''"></span>
					<span class="mono" x-text="closeResult ? money(Math.abs(closeResult.difference)) : ''"></span>
				</div>
				<span class="pay__col-label"><?php esc_html_e( 'DESGLOSE POR MEDIO', 'keytill' ); ?></span>
				<template x-for="b in (closeResult ? closeResult.breakdown : [])" :key="b.id">
					<div class="cash-brk" x-show="b.count > 0">
						<span x-text="b.label"></span>
						<span class="mono"><span x-text="money(b.total)"></span> · <span x-text="b.count"></span></span>
					</div>
				</template>
				<template x-if="closeResult && closeResult.by_cashier && closeResult.by_cashier.length">
					<div class="cash-cashiers">
						<span class="pay__col-label"><?php esc_html_e( 'VENTAS POR CAJERO', 'keytill' ); ?></span>
						<template x-for="c in closeResult.by_cashier" :key="c.id">
							<div class="cash-brk">
								<span x-text="c.name"></span>
								<span class="mono"><span x-text="money(c.total)"></span> · <span x-text="c.count"></span></span>
							</div>
						</template>
					</div>
				</template>
				<div class="pay__enter" x-show="!$store.pos.touch"><kbd class="kbd-brand">ENTER</kbd> <?php esc_html_e( 'volver a apertura', 'keytill' ); ?></div>
				<button type="button" class="sheet-btn sheet-btn--primary" x-show="$store.pos.touch" x-cloak @click="finishClose()"><?php esc_html_e( 'Volver a apertura', 'keytill' ); ?></button>
			</div>
		</div>
	</div>

	<!-- ================= TOAST ================= -->
	<div class="keytill-toast-wrap" x-show="toast" x-cloak>
		<div class="keytill-toast" :class="toast && toast.kind === 'error' ? 'is-error' : 'is-ok'">
			<span class="mono keytill-toast__kicker" x-text="toast ? toast.kicker : ''"></span>
			<span class="keytill-toast__title" x-text="toast ? toast.title : ''"></span>
			<span class="mono keytill-toast__detail" x-text="toast ? toast.detail : ''"></span>
		</div>
	</div>
</div>
