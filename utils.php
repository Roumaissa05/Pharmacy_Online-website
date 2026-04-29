<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

// ✅ إصلاح CORS: قبول أي origin يرسله المتصفح (ضروري للـ credentials)
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

// قائمة بيضاء للـ origins المسموح بها
$allowedOrigins = [
    "http://localhost",
    "http://127.0.0.1",
    "http://localhost:80",
    "http://127.0.0.1:80",
    // أضف port مشروعك هنا إذا كان مختلفاً، مثلاً:
    // "http://localhost:3000",
];

if ($origin !== "" && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
} elseif ($origin === "") {
    // طلب مباشر (مثلاً من نفس المجال) — لا نحتاج CORS header
} else {
    // origin غير معروف — نسمح به في بيئة التطوير فقط
    // ❌ احذف السطر التالي في بيئة الإنتاج
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Vary: Origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// معالجة طلبات OPTIONS المسبقة (preflight)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// ✅ بدء الـ session بإعدادات آمنة
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => false,   // اجعلها true إذا كنت تستخدم HTTPS
        "httponly" => true,
        "samesite" => "Lax",   // ✅ مهم جداً مع credentials
    ]);
    session_start();
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $raw = file_get_contents("php://input");
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function current_user_id(): ?int
{
    return isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : null;
}

function current_user_role(): ?string
{
    return isset($_SESSION["role"]) ? (string)$_SESSION["role"] : null;
}

function require_auth(): int
{
    $uid = current_user_id();
    if (!$uid) {
        respond(["ok" => false, "error" => "Authentication required"], 401);
    }
    return $uid;
}
