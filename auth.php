<?php
declare(strict_types=1);

require __DIR__ . "/utils.php";
require __DIR__ . "/db.php";

$action = $_GET["action"] ?? "session";

// ─── GET: حالة الجلسة الحالية ───────────────────────────────────────────────
if ($action === "session") {
    if (!isset($_SESSION["user_id"])) {
        respond(["ok" => true, "user" => null]);
    }
    respond([
        "ok"   => true,
        "user" => [
            "id"       => (int)$_SESSION["user_id"],
            "username" => (string)$_SESSION["username"],
            "role"     => (string)$_SESSION["role"],
        ],
    ]);
}

// ─── باقي العمليات تتطلب POST ───────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["ok" => false, "error" => "Method not allowed"], 405);
}

$input = body();

// ─── تسجيل حساب جديد ────────────────────────────────────────────────────────
if ($action === "register") {
    $username = trim((string)($input["username"] ?? ""));
    $password = (string)($input["password"] ?? "");
    $role     = (string)($input["role"] ?? "client");

    // السماح فقط بأدوار معروفة
    if (!in_array($role, ["client", "staff"], true)) {
        $role = "client";
    }

    if ($username === "" || $password === "") {
        respond(["ok" => false, "error" => "Username and password are required"], 422);
    }

    // ✅ التحقق من طول كلمة المرور
    if (strlen($password) < 4) {
        respond(["ok" => false, "error" => "Password must be at least 4 characters"], 422);
    }

    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        respond(["ok" => false, "error" => "Username already exists"], 409);
    }

    // ✅ تشفير كلمة المرور دائماً بـ bcrypt
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)"
    );
    $stmt->execute([$username, $hash, $role]);

    respond(["ok" => true]);
}

// ─── تسجيل الدخول ────────────────────────────────────────────────────────────
if ($action === "login") {
    $username = trim((string)($input["username"] ?? ""));
    $password = (string)($input["password"] ?? "");

    if ($username === "" || $password === "") {
        respond(["ok" => false, "error" => "Username and password are required"], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, role FROM users WHERE username = ?"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(["ok" => false, "error" => "Invalid credentials"], 401);
    }

    $storedHash = (string)$user["password_hash"];

    // ✅ إصلاح: دعم الحسابات القديمة (plain text) والجديدة (bcrypt) معاً
    // للحسابات المخزّنة كـ plain text في SQL (مثل client1/123)
    $isPlainText = !str_starts_with($storedHash, '$2');
    if ($isPlainText) {
        // ⚠️ كلمة المرور غير مشفّرة — نتحقق منها ثم نحوّلها تلقائياً لـ bcrypt
        $valid = ($password === $storedHash);
        if ($valid) {
            // ✅ ترقية تلقائية: خزّن النسخة المشفّرة بدلاً من plain text
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$newHash, $user["id"]]);
        }
    } else {
        // حساب عادي بكلمة مرور مشفّرة
        $valid = password_verify($password, $storedHash);
    }

    if (!$valid) {
        respond(["ok" => false, "error" => "Invalid credentials"], 401);
    }

    // ✅ تجديد الـ session ID لمنع Session Fixation
    session_regenerate_id(true);

    $_SESSION["user_id"]  = (int)$user["id"];
    $_SESSION["username"] = (string)$user["username"];
    $_SESSION["role"]     = (string)$user["role"];

    respond([
        "ok"   => true,
        "user" => [
            "id"       => (int)$user["id"],
            "username" => (string)$user["username"],
            "role"     => (string)$user["role"],
        ],
    ]);
}

// ─── تسجيل الخروج ────────────────────────────────────────────────────────────
if ($action === "logout") {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    respond(["ok" => true]);
}

respond(["ok" => false, "error" => "Unknown action"], 404);
