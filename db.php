<?php
declare(strict_types=1);

// ⚠️ عدّل هذه القيم حسب إعدادات XAMPP/WAMP عندك
$host   = "127.0.0.1";
$dbName = "vitapharm_db";
$dbUser = "root";
$dbPass = "";          // ← ضع كلمة مرور MySQL هنا إذا كانت مضبوطة

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    // لا تكشف تفاصيل الخطأ للمستخدم في الإنتاج
    echo json_encode(["ok" => false, "error" => "Database connection failed"]);
    exit;
}
