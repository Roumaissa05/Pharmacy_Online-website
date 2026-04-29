<?php
declare(strict_types=1);

require __DIR__ . "/utils.php";
require __DIR__ . "/db.php";

$userId = require_auth();
$role   = current_user_role() ?? "client";
$action = $_GET["action"] ?? "list";

// ─── جلب قائمة الطلبات ───────────────────────────────────────────────────────
if ($action === "list") {
    if (in_array($role, ["staff", "admin"], true)) {
        // الموظف والمدير يرون جميع الطلبات
        $stmt = $pdo->query(
            "SELECT o.id,
                    DATE(o.created_at) AS date,
                    o.status,
                    o.total_dzd        AS totalDZD,
                    o.total_usd        AS totalUSD,
                    o.payment,
                    o.customer_name    AS customer
             FROM orders o
             ORDER BY o.created_at DESC"
        );
    } else {
        // العميل يرى طلباته فقط
        $stmt = $pdo->prepare(
            "SELECT o.id,
                    DATE(o.created_at) AS date,
                    o.status,
                    o.total_dzd        AS totalDZD,
                    o.total_usd        AS totalUSD,
                    o.payment,
                    o.customer_name    AS customer
             FROM orders o
             WHERE o.user_id = ?
             ORDER BY o.created_at DESC"
        );
        $stmt->execute([$userId]);
    }

    $orders = $stmt->fetchAll();

    // جلب عناصر كل طلب
    $itemStmt = $pdo->prepare(
        "SELECT product_id AS id,
                name_ar    AS nameAr,
                name_en    AS nameEn,
                qty,
                price_dzd  AS priceDZD,
                price_usd  AS priceUSD
         FROM order_items
         WHERE order_id = ?"
    );

    foreach ($orders as &$order) {
        $order["totalDZD"] = (float)$order["totalDZD"];
        $order["totalUSD"] = (float)$order["totalUSD"];
        $itemStmt->execute([$order["id"]]);
        $items = $itemStmt->fetchAll();
        foreach ($items as &$item) {
            $item["id"]       = (int)$item["id"];
            $item["qty"]      = (int)$item["qty"];
            $item["priceDZD"] = (float)$item["priceDZD"];
            $item["priceUSD"] = (float)$item["priceUSD"];
        }
        unset($item);
        $order["items"] = $items;
    }
    unset($order);

    respond(["ok" => true, "orders" => $orders]);
}

// ─── باقي العمليات تتطلب POST ───────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["ok" => false, "error" => "Method not allowed"], 405);
}

$input = body();

// ─── إنشاء طلب جديد ──────────────────────────────────────────────────────────
if ($action === "create") {
    $payment = (string)($input["payment"] ?? "cash");
    $items   = $input["items"] ?? [];

    if (!in_array($payment, ["cash", "gold", "card"], true)) {
        $payment = "cash";
    }

    if (!is_array($items) || count($items) === 0) {
        respond(["ok" => false, "error" => "Cart is empty"], 422);
    }

    $totalDZD   = 0.0;
    $totalUSD   = 0.0;
    $cleanItems = [];

    foreach ($items as $item) {
        $productId = (int)($item["id"] ?? 0);
        $nameAr    = trim((string)($item["nameAr"] ?? ""));
        $nameEn    = trim((string)($item["nameEn"] ?? ""));
        $priceDZD  = (float)($item["priceDZD"] ?? 0);
        $priceUSD  = (float)($item["priceUSD"] ?? 0);
        $qty       = max(1, (int)($item["qty"] ?? 1));

        if ($productId <= 0 || $nameAr === "" || $nameEn === "") {
            continue;
        }

        $totalDZD    += $priceDZD * $qty;
        $totalUSD    += $priceUSD * $qty;
        $cleanItems[] = compact(
            "productId", "nameAr", "nameEn",
            "priceDZD", "priceUSD", "qty"
        );
    }

    if (count($cleanItems) === 0) {
        respond(["ok" => false, "error" => "Invalid order items"], 422);
    }

    // ✅ إصلاح: استخدام username من الـ SESSION (مصدر موثوق) وليس من الـ input
    $orderId  = "ORD-" . time() . "-" . random_int(100, 999);
    $customer = (string)($_SESSION["username"] ?? "Guest");

    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare(
            "INSERT INTO orders
                 (id, user_id, customer_name, status, payment, total_dzd, total_usd)
             VALUES (?, ?, ?, 'Pending', ?, ?, ?)"
        );
        $orderStmt->execute([
            $orderId, $userId, $customer,
            $payment, $totalDZD, $totalUSD,
        ]);

        $itemStmt = $pdo->prepare(
            "INSERT INTO order_items
                 (order_id, product_id, name_ar, name_en, price_dzd, price_usd, qty)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($cleanItems as $item) {
            $itemStmt->execute([
                $orderId,
                $item["productId"],
                $item["nameAr"],
                $item["nameEn"],
                $item["priceDZD"],
                $item["priceUSD"],
                $item["qty"],
            ]);
        }

        // تفريغ سلة المستخدم بعد إتمام الطلب
        $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?")
            ->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(["ok" => false, "error" => "Failed to create order"], 500);
    }

    // بناء عناصر الاستجابة بنفس شكل cleanItems لكن بـ id بدلاً من productId
    $responseItems = array_map(fn($i) => [
        "id"       => $i["productId"],
        "nameAr"   => $i["nameAr"],
        "nameEn"   => $i["nameEn"],
        "priceDZD" => $i["priceDZD"],
        "priceUSD" => $i["priceUSD"],
        "qty"      => $i["qty"],
    ], $cleanItems);

    respond([
        "ok"    => true,
        "order" => [
            "id"       => $orderId,
            "date"     => date("Y-m-d"),
            "status"   => "Pending",
            "totalDZD" => $totalDZD,
            "totalUSD" => $totalUSD,
            "items"    => $responseItems,
            "payment"  => $payment,
            "customer" => $customer,
        ],
    ]);
}

// ─── تحديث حالة الطلب (للموظف والمدير فقط) ─────────────────────────────────
if ($action === "update_status") {
    if (!in_array($role, ["staff", "admin"], true)) {
        respond(["ok" => false, "error" => "Forbidden"], 403);
    }

    $id     = trim((string)($input["id"] ?? ""));
    $status = (string)($input["status"] ?? "");

    if ($id === "") {
        respond(["ok" => false, "error" => "Order id is required"], 422);
    }

    if (!in_array($status, ["Pending", "Processing", "Completed"], true)) {
        respond(["ok" => false, "error" => "Invalid status value"], 422);
    }

    // ✅ التحقق من أن الطلب موجود فعلاً
    $check = $pdo->prepare("SELECT id FROM orders WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        respond(["ok" => false, "error" => "Order not found"], 404);
    }

    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")
        ->execute([$status, $id]);

    respond(["ok" => true]);
}

respond(["ok" => false, "error" => "Unknown action"], 404);
