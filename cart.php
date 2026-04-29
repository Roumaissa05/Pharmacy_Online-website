<?php
declare(strict_types=1);

require __DIR__ . "/utils.php";
require __DIR__ . "/db.php";

$userId = require_auth();
$action = $_GET["action"] ?? "get";

// ─── جلب محتوى السلة ─────────────────────────────────────────────────────────
if ($action === "get") {
    $stmt = $pdo->prepare(
        "SELECT product_id AS id,
                name_ar     AS nameAr,
                name_en     AS nameEn,
                price_dzd   AS priceDZD,
                price_usd   AS priceUSD,
                qty
         FROM cart_items
         WHERE user_id = ?
         ORDER BY id DESC"
    );
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    // ✅ تحويل الأنواع لضمان التوافق مع JavaScript
    foreach ($items as &$item) {
        $item["id"]       = (int)$item["id"];
        $item["priceDZD"] = (float)$item["priceDZD"];
        $item["priceUSD"] = (float)$item["priceUSD"];
        $item["qty"]      = (int)$item["qty"];
    }
    unset($item);

    respond(["ok" => true, "items" => $items]);
}

// ─── باقي العمليات تتطلب POST ───────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["ok" => false, "error" => "Method not allowed"], 405);
}

$input = body();

// ─── إضافة منتج للسلة ────────────────────────────────────────────────────────
if ($action === "add") {
    $productId = (int)($input["id"] ?? 0);
    $qtyDelta  = (int)($input["qtyDelta"] ?? 1);
    $nameAr    = trim((string)($input["nameAr"] ?? ""));
    $nameEn    = trim((string)($input["nameEn"] ?? ""));
    $priceDZD  = (float)($input["priceDZD"] ?? 0);
    $priceUSD  = (float)($input["priceUSD"] ?? 0);

    if ($productId <= 0 || $nameAr === "" || $nameEn === "") {
        respond(["ok" => false, "error" => "Invalid product data"], 422);
    }

    // ✅ التحقق من أن الكمية معقولة
    if ($qtyDelta === 0) {
        respond(["ok" => true]); // لا شيء يتغير
    }

    $stmt = $pdo->prepare(
        "INSERT INTO cart_items
             (user_id, product_id, name_ar, name_en, price_dzd, price_usd, qty)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             name_ar   = VALUES(name_ar),
             name_en   = VALUES(name_en),
             price_dzd = VALUES(price_dzd),
             price_usd = VALUES(price_usd),
             qty       = GREATEST(0, qty + VALUES(qty))"
    );
    $stmt->execute([
        $userId, $productId, $nameAr, $nameEn,
        $priceDZD, $priceUSD, $qtyDelta,
    ]);

    // احذف العناصر التي وصل qty إلى 0 أو أقل
    $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND qty <= 0")
        ->execute([$userId]);

    respond(["ok" => true]);
}

// ─── تعيين كمية محددة ────────────────────────────────────────────────────────
if ($action === "set_qty") {
    $productId = (int)($input["id"] ?? 0);
    $qty       = (int)($input["qty"] ?? 0);

    if ($productId <= 0) {
        respond(["ok" => false, "error" => "Invalid product id"], 422);
    }

    if ($qty <= 0) {
        $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?")
            ->execute([$userId, $productId]);
        respond(["ok" => true]);
    }

    $pdo->prepare("UPDATE cart_items SET qty = ? WHERE user_id = ? AND product_id = ?")
        ->execute([$qty, $userId, $productId]);

    respond(["ok" => true]);
}

// ─── حذف منتج من السلة ───────────────────────────────────────────────────────
if ($action === "remove") {
    $productId = (int)($input["id"] ?? 0);
    if ($productId <= 0) {
        respond(["ok" => false, "error" => "Invalid product id"], 422);
    }
    $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?")
        ->execute([$userId, $productId]);
    respond(["ok" => true]);
}

// ─── تفريغ السلة بالكامل ─────────────────────────────────────────────────────
if ($action === "clear") {
    $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?")
        ->execute([$userId]);
    respond(["ok" => true]);
}

respond(["ok" => false, "error" => "Unknown action"], 404);
