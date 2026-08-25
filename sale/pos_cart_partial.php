<!-- Cart Header -->
<div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between shrink-0">
    <div>
        <h2 class="font-bold text-gray-900 dark:text-gray-100">Current Order</h2>
        <p class="text-xs text-gray-500"><?= count($_SESSION['sale_cart'] ?? []) ?> item(s) in cart</p>
    </div>
    <?php if (count($_SESSION['sale_cart'] ?? []) > 0): ?>
        <a href="pos.php?clear_cart=1" onclick="handleRemoveFromCart(event, this.href)" class="text-xs text-red-500 hover:text-red-700 font-medium px-2 py-1 rounded-lg hover:bg-red-50 transition clear-cart-btn">Clear All</a>
    <?php endif; ?>
</div>

<!-- Cart Items -->
<div class="flex-1 overflow-y-auto px-4 py-2">
    <?php if (count($_SESSION['sale_cart'] ?? []) > 0): ?>
        <form method="POST" id="cartForm">
            <?php foreach ($_SESSION['sale_cart'] as $key => $item): ?>
                <div class="cart-item rounded-lg p-3 mb-2 border border-gray-100">
                    <div class="flex justify-between items-start">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-gray-800 truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                            <p class="text-indigo-600 font-bold text-sm"><?= number_format($item['price']) ?> Ks</p>
                        </div>
                        <a href="pos.php?remove=<?= $key ?>" onclick="handleRemoveFromCart(event, this.href)" class="text-gray-300 hover:text-red-500 ml-2 transition p-1 remove-cart-btn" title="Remove">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </a>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <div class="flex items-center gap-1">
                            <button type="button" class="qty-btn bg-gray-100 text-gray-600 hover:bg-gray-200" onclick="changeQty(this, -1)">−</button>
                            <input type="number" name="quantity[<?= $key ?>]" value="<?= $item['quantity'] ?>" min="1" data-price="<?= $item['price'] ?>" data-key="<?= $key ?>" class="cart-qty border border-gray-200 rounded-lg w-14 p-1 text-center text-sm focus:outline-none focus:border-indigo-400">
                            <button type="button" class="qty-btn bg-gray-100 text-gray-600 hover:bg-gray-200" onclick="changeQty(this, 1)">+</button>
                        </div>
                        <span class="font-bold text-sm text-gray-800 text-right min-w-[80px] shrink-0 item-total-<?= $key ?>"><?= number_format($item['total']) ?> Ks</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
    <?php else: ?>
        <div class="flex flex-col items-center justify-center h-full text-gray-400 py-12">
            <div class="text-5xl mb-3">🛒</div>
            <p class="font-medium">Cart is empty</p>
            <p class="text-xs mt-1">Add products to start a sale</p>
        </div>
    <?php endif; ?>
</div>

<?php if (count($_SESSION['sale_cart'] ?? []) > 0): ?>
    <!-- Cart Summary -->
    <div class="border-t border-gray-200 px-4 py-3 space-y-2 shrink-0 bg-white">
        <div class="flex justify-between text-sm">
            <span class="text-gray-500">Subtotal</span>
            <span class="font-semibold text-gray-800" id="cartSubtotal"><?= number_format($cart_subtotal) ?> Ks</span>
        </div>
        <div class="flex justify-between text-sm items-center">
            <span class="text-gray-500">Discount (%)</span>
            <div class="flex items-center gap-1">
                <input type="number" form="paymentForm" name="discount" id="discountInput" value="0" min="0" max="100" step="0.01" class="border border-gray-200 rounded-lg w-20 text-right p-1.5 text-sm focus:outline-none focus:border-indigo-400" oninput="updateTotals()">
                <span class="text-gray-400 text-xs">%</span>
            </div>
        </div>
        <div class="border-t border-gray-100 pt-2 flex justify-between items-center">
            <span class="font-bold text-gray-900 dark:text-gray-100">Total</span>
            <span class="font-bold text-xl text-indigo-600" id="grandTotalDisplay"><?= number_format($cart_subtotal) ?> Ks</span>
        </div>
        <button onclick="showPaymentModal()" class="w-full bg-indigo-600 text-white py-3 rounded-xl hover:bg-indigo-700 font-bold text-sm flex items-center justify-center gap-2 transition mt-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" /></svg>
            Checkout
        </button>
    </div>
<?php endif; ?>
