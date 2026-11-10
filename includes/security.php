<?php
/**
 * ความปลอดภัยกลางของระบบ (โหลดจาก session.php ก่อนทุกหน้า)
 *
 *  - ซ่อนรายละเอียดข้อผิดพลาด, ส่ง security header + CSP แบบ nonce
 *  - กัน CSRF ข้ามเว็บด้วย Origin / Sec-Fetch-Site (เสริมจาก token)
 *  - session ที่แข็งแรง: ชื่อเฉพาะ, โฟลเดอร์เฉพาะ, strict mode, หมดเวลา, ผูกกับเบราว์เซอร์
 *  - จำกัดความถี่, ตัวกันบอต, นโยบายรหัสผ่าน, รหัสผ่านแบบ Argon2id
 *  - เข้ารหัสความลับ (AES-256-GCM), TOTP สำหรับยืนยันตัวตนสองขั้นตอน
 *  - รับรูปอัปโหลดแล้ว "วาดใหม่" เพื่อล้าง EXIF และโค้ดแฝง
 */

const ADMIN_IDLE_SECONDS    = 3600;    // แอดมินไม่ใช้งานนานกว่านี้ ต้องเข้าสู่ระบบใหม่
const ADMIN_MAX_SECONDS     = 43200;   // อายุสูงสุดของการเข้าสู่ระบบแอดมิน (12 ชม.)
const CUSTOMER_IDLE_SECONDS = 14400;   // ลูกค้า 4 ชม.
const TWOFA_PENDING_SECONDS = 300;     // เวลากรอกรหัสยืนยันสองขั้นตอนหลังใส่รหัสผ่านถูก
const BOT_MIN_SECONDS       = 2;       // ฟอร์มที่ส่งเร็วกว่านี้ถือว่าไม่ใช่คน
const BOT_MAX_SECONDS       = 86400;
const MAX_UPLOAD_BYTES      = 2097152;

/** โหมดทดสอบอัตโนมัติ (ตั้งผ่านตัวแปรสภาพแวดล้อมของเซิร์ฟเวอร์ทดสอบเท่านั้น ห้ามตั้งบนเว็บจริง) */
function za_test_mode(): bool
{
    return getenv("ZA_TEST_MODE") === "1";
}

function is_https(): bool
{
    return (!empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off")
        || (string)($_SERVER["SERVER_PORT"] ?? "") === "443";
}

function csp_nonce(): string
{
    return $GLOBALS["za_csp_nonce"] ?? "";
}

function user_agent_hash(): string
{
    return hash("sha256", (string)($_SERVER["HTTP_USER_AGENT"] ?? ""));
}

/* ======================================================================
   หน้าแสดงข้อผิดพลาดกลาง (ไม่เผยพาธหรือรายละเอียดภายใน)
   ====================================================================== */

function security_error_page(int $code, string $message = "ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง"): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header("Content-Type: text/html; charset=UTF-8");
    }

    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>เกิดข้อผิดพลาด</title></head>'
        . '<body style="font-family:Tahoma,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8f3ec;color:#27344a">'
        . '<main style="text-align:center;padding:24px"><h1 style="font-size:24px;margin:0 0 8px">' . htmlspecialchars((string)$code, ENT_QUOTES, "UTF-8") . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES, "UTF-8") . '</p>'
        . '<p><a href="javascript:history.back()" style="color:#626b91">กลับ</a></p></main></body></html>';
    exit;
}

/* ======================================================================
   เริ่มต้นระบบความปลอดภัย
   ====================================================================== */

function security_bootstrap(): void
{
    static $done = false;

    if ($done) {
        return;
    }
    $done = true;

    // ข้อผิดพลาดบันทึกลง log เท่านั้น ไม่แสดงให้ผู้ใช้เห็น
    ini_set("display_errors", "0");
    ini_set("display_startup_errors", "0");
    ini_set("log_errors", "1");
    error_reporting(E_ALL);

    if (PHP_SAPI === "cli") {
        return;
    }

    set_exception_handler(function (Throwable $e) {
        error_log("Uncaught " . get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        security_error_page(500);
    });

    register_shutdown_function(function () {
        $error = error_get_last();

        if ($error && in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            security_error_page(500);
        }
    });

    header_remove("X-Powered-By");

    // เว็บนี้ใช้เฉพาะ GET / POST / HEAD
    if (!in_array($_SERVER["REQUEST_METHOD"] ?? "GET", ["GET", "POST", "HEAD"], true)) {
        header("Allow: GET, POST, HEAD");
        security_error_page(405, "ไม่รองรับวิธีการร้องขอนี้");
    }

    /* ----- Security headers + CSP แบบ nonce ----- */
    $nonce = bin2hex(random_bytes(16));
    $GLOBALS["za_csp_nonce"] = $nonce;

    $csp = [
        "default-src 'none'",
        // สคริปต์: ของเว็บเอง + สคริปต์ที่มี nonce + ไลบรารี QR ไฟล์เดียวที่ระบุพาธเต็ม
        "script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js",
        "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
        "style-src-attr 'unsafe-inline'",
        "font-src https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "connect-src 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "base-uri 'none'",
        "object-src 'none'",
    ];

    if (za_test_mode()) {
        $csp[] = "frame-src 'self'";   // ให้เครื่องมือทดสอบวัดหน้าผ่าน iframe ได้
    }

    header("Content-Security-Policy: " . implode("; ", $csp));
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: same-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=()");
    header("Cross-Origin-Opener-Policy: same-origin");
    header("X-Permitted-Cross-Domain-Policies: none");

    if (is_https()) {
        header("Strict-Transport-Security: max-age=31536000");
    }

    /* ----- กัน CSRF ข้ามเว็บ: คำขอ POST ต้องมาจากหน้าของเว็บนี้เอง ----- */
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
        $site   = (string)($_SERVER["HTTP_SEC_FETCH_SITE"] ?? "");
        $origin = (string)($_SERVER["HTTP_ORIGIN"] ?? "");
        $reject = $site !== "" && !in_array($site, ["same-origin", "none"], true);

        if (!$reject && $origin !== "") {
            $normalize = function (string $host, string $scheme): string {
                $host = strtolower($host);
                $default = $scheme === "https" ? ":443" : ":80";

                return str_ends_with($host, $default) ? substr($host, 0, -strlen($default)) : $host;
            };

            $parts  = parse_url($origin);
            $scheme = strtolower((string)($parts["scheme"] ?? ""));
            $oHost  = $normalize(($parts["host"] ?? "") . (isset($parts["port"]) ? ":" . $parts["port"] : ""), $scheme);
            $hHost  = $normalize((string)($_SERVER["HTTP_HOST"] ?? ""), is_https() ? "https" : "http");

            $reject = $origin === "null" || $oHost === "" || $oHost !== $hHost;
        }

        if ($reject) {
            security_error_page(403, "คำขอไม่ได้มาจากหน้าของเว็บนี้ จึงถูกปฏิเสธ");
        }
    }

    /* ----- Session ----- */
    $https = is_https();
    $dir   = dirname(__DIR__) . "/storage/sessions";

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Lax");
    ini_set("session.gc_maxlifetime", "86400");

    // ชื่อเฉพาะของเว็บนี้ ไม่ปนกับโปรเจกต์อื่นบน localhost
    session_name($https ? "__Host-ZA3SID" : "ZA3SID");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "secure"   => $https,
        "httponly" => true,
        "samesite" => "Lax",
    ]);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    session_guard();
}

function admin_session_clear(): void
{
    unset(
        $_SESSION["admin_id"], $_SESSION["admin_username"], $_SESSION["admin_name"], $_SESSION["admin_role"],
        $_SESSION["admin_start"], $_SESSION["admin_last"], $_SESSION["admin_ua"], $_SESSION["admin_pwfp"],
        $_SESSION["admin_2fa"]
    );
}

function customer_session_clear(): void
{
    unset($_SESSION["customer_id"], $_SESSION["customer_last"], $_SESSION["customer_pwfp"]);
}

/** หมดเวลา / ถูกขโมย session: ตัดการเข้าสู่ระบบทันที */
function session_guard(): void
{
    $now = time();

    $adminIdle = ADMIN_IDLE_SECONDS;
    if (za_test_mode() && (int)getenv("ZA_ADMIN_IDLE") > 0) {
        $adminIdle = (int)getenv("ZA_ADMIN_IDLE");
    }

    if (isset($_SESSION["admin_id"])) {
        $expired = $now - (int)($_SESSION["admin_last"] ?? 0) > $adminIdle
            || $now - (int)($_SESSION["admin_start"] ?? 0) > ADMIN_MAX_SECONDS;
        $moved   = !hash_equals((string)($_SESSION["admin_ua"] ?? ""), user_agent_hash());

        if ($expired || $moved) {
            admin_session_clear();
            session_regenerate_id(true);
            $_SESSION["flash"][] = ["type" => "error", "message" => $moved
                ? "ตรวจพบการใช้งานจากเบราว์เซอร์อื่น จึงออกจากระบบเพื่อความปลอดภัย"
                : "หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่"];
        } elseif (empty($_SERVER["HTTP_X_IDLE_POLL"])) {
            // คำขอเช็กแจ้งเตือนอัตโนมัติไม่ถือว่าเป็นการใช้งาน
            $_SESSION["admin_last"] = $now;
        }
    }

    if (isset($_SESSION["admin_2fa"]) && $now - (int)($_SESSION["admin_2fa"]["at"] ?? 0) > TWOFA_PENDING_SECONDS) {
        unset($_SESSION["admin_2fa"]);
    }

    if (isset($_SESSION["customer_id"])) {
        if ($now - (int)($_SESSION["customer_last"] ?? $now) > CUSTOMER_IDLE_SECONDS) {
            customer_session_clear();
            $_SESSION["flash"][] = ["type" => "error", "message" => "หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่"];
        } else {
            $_SESSION["customer_last"] = $now;
        }
    }
}

/* ======================================================================
   กุญแจและการเข้ารหัส (ไฟล์กุญแจอยู่ใน storage/ ซึ่งเว็บเปิดตรงไม่ได้ และแยกจากฐานข้อมูล)
   ====================================================================== */

function app_key(): string
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $file = dirname(__DIR__) . "/storage/app.key";

    for ($attempt = 0; $attempt < 3; $attempt++) {
        if (is_file($file)) {
            $raw = base64_decode(trim((string)file_get_contents($file)), true);

            if ($raw !== false && strlen($raw) === 32) {
                return $key = $raw;
            }
        }

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }

        // สร้างแบบ exclusive: ถ้ามีคำขออื่นสร้างไปก่อน จะวนไปอ่านไฟล์นั้นแทน
        $handle = @fopen($file, "xb");

        if ($handle) {
            fwrite($handle, base64_encode(random_bytes(32)));
            fclose($handle);
        }
    }

    throw new RuntimeException("ไม่สามารถสร้างกุญแจระบบได้ (โฟลเดอร์ storage ต้องเขียนได้)");
}

function subkey(string $purpose): string
{
    return hash_hmac("sha256", $purpose, app_key(), true);
}

function secret_encrypt(string $plain): string
{
    $iv     = random_bytes(12);
    $cipher = openssl_encrypt($plain, "aes-256-gcm", subkey("secrets"), OPENSSL_RAW_DATA, $iv, $tag);

    return base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(?string $blob): ?string
{
    $raw = $blob ? base64_decode($blob, true) : false;

    if ($raw === false || strlen($raw) < 29) {
        return null;
    }

    $plain = openssl_decrypt(substr($raw, 28), "aes-256-gcm", subkey("secrets"), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

    return $plain === false ? null : $plain;
}

/** ลายนิ้วมือของรหัสผ่านปัจจุบัน: ถ้ารหัสถูกเปลี่ยน session เดิมทุกเครื่องจะหลุดทันที */
function password_fingerprint(string $hash): string
{
    return substr(hash_hmac("sha256", $hash, subkey("pwfp")), 0, 24);
}

/* ======================================================================
   รหัสผ่าน
   ====================================================================== */

/** แฮชสำรองสำหรับตรวจรหัสผ่านเมื่อไม่พบบัญชี เพื่อให้ใช้เวลาใกล้เคียงกัน ไม่เผยว่ามีบัญชีหรือไม่ */
const PASSWORD_DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$NTFreGNqamxlV1NGRC4wUQ$wbcSFloc8H3N5ZYCpqT82lDgbFgYki2A7GUHc+suH8M';

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID, ["memory_cost" => 19456, "time_cost" => 2, "threads" => 1]);
}

function password_needs_upgrade(string $hash): bool
{
    return password_needs_rehash($hash, PASSWORD_ARGON2ID, ["memory_cost" => 19456, "time_cost" => 2, "threads" => 1]);
}

/** ตรวจรหัสผ่านโดยใช้เวลาใกล้เคียงกันไม่ว่าจะมีบัญชีหรือไม่ */
function verify_password_constant_time(string $password, ?string $hash): bool
{
    $ok = password_verify(mb_substr($password, 0, 200), $hash ?? PASSWORD_DUMMY_HASH);

    return $hash !== null && $ok;
}

const COMMON_PASSWORDS = "password passw0rd p@ssw0rd p@ssword password1 password12 password123 password1234 pass1234 qwerty qwerty123 qwertyuiop qwertyui "
    . "asdfghjkl asdfgh zxcvbnm 123456 1234567 12345678 123456789 1234567890 0123456789 987654321 111111 000000 123123 654321 abc123 abcd1234 abcdef "
    . "iloveyou iloveyou1 letmein welcome welcome1 admin admin123 administrator root toor changeme default guest login master monkey dragon sunshine "
    . "princess football baseball superman batman trustno1 shadow michael jennifer jordan hello hello123 freedom whatever starwars access secret "
    . "internet computer cookie shopping stationery sazaa 3za 3zastationery sa2 kawin thailand bangkok chiangmai 5555 55555 5555555 1q2w3e4r 1q2w3e 1qaz2wsx "
    . "q1w2e3r4 zaq12wsx qazwsx qazwsxedc dubsmash lovely loveyou test test123 testing demo user user123 temp temp123 secret123";

function password_is_common(string $lower): bool
{
    static $set = null;

    if ($set === null) {
        $set = array_flip(explode(" ", COMMON_PASSWORDS));
    }

    $base = preg_replace('/[0-9!@#$%^&*._\-]+$/u', "", $lower);
    $leet = strtr($base, ["0" => "o", "1" => "i", "3" => "e", "4" => "a", "5" => "s", "7" => "t", "@" => "a", "\$" => "s"]);
    $tail = strlen($lower) - strlen($base);

    return isset($set[$lower])
        || ($base !== "" && $tail <= 6 && (isset($set[$base]) || isset($set[$leet])))
        || (bool)preg_match('/^(?:0123456789|1234567890|abcdefghij|qwertyuiop|asdfghjkl|zxcvbnm)/', $lower);
}

/**
 * ตรวจรหัสผ่านใหม่ คืนข้อความปัญหา หรือ null ถ้าใช้ได้
 * @param array $personal ข้อความส่วนตัวที่ห้ามอยู่ในรหัสผ่าน เช่น ชื่อผู้ใช้ อีเมล
 */
function password_problem(string $password, int $minLength = 8, array $personal = []): ?string
{
    if (mb_strlen($password) < $minLength) {
        return "รหัสผ่านต้องมีอย่างน้อย {$minLength} ตัวอักษร";
    }
    if (strlen($password) > 128) {
        return "รหัสผ่านยาวเกินไป (ไม่เกิน 128 ตัวอักษร)";
    }

    $lower = mb_strtolower($password);

    if (count(array_unique(preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY))) < 5) {
        return "รหัสผ่านซ้ำตัวอักษรมากเกินไป กรุณาใช้ตัวอักษรให้หลากหลายขึ้น";
    }
    if (password_is_common($lower)) {
        return "รหัสผ่านนี้เป็นแบบที่คนใช้กันบ่อยและเดาง่าย กรุณาตั้งรหัสอื่น (แนะนำวลียาว ๆ ที่จำง่าย)";
    }

    foreach ($personal as $word) {
        $word = mb_strtolower(trim((string)$word));

        if (mb_strlen($word) >= 4 && str_contains($lower, $word)) {
            return "รหัสผ่านต้องไม่มีชื่อผู้ใช้หรืออีเมลของคุณอยู่ในนั้น";
        }
    }

    return null;
}

/* ======================================================================
   จำกัดความถี่ (ใช้ตาราง login_attempts ร่วมกัน)
   ====================================================================== */

function throttle_blocked(string $scope, string $key, int $max, int $seconds): bool
{
    global $conn;

    $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts
        WHERE scope = ? AND identifier = ? AND ip = ? AND attempted_at > NOW() - INTERVAL " . (int)$seconds . " SECOND");
    $stmt->execute([substr($scope, 0, 20), mb_substr(mb_strtolower($key), 0, 190), client_ip()]);

    return (int)$stmt->fetchColumn() >= $max;
}

function throttle_hit(string $scope, string $key = ""): void
{
    global $conn;

    $conn->prepare("INSERT INTO login_attempts (scope, identifier, ip) VALUES (?, ?, ?)")
         ->execute([substr($scope, 0, 20), mb_substr(mb_strtolower($key), 0, 190), client_ip()]);

    if (random_int(1, 25) === 1) {
        $conn->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    }
}

/* ======================================================================
   ตัวกันบอต: ช่องดักบอต + ตรวจเวลากรอกฟอร์มที่ลงลายเซ็นไว้
   ====================================================================== */

function bot_fields(): string
{
    $ts  = time();
    $sig = hash_hmac("sha256", $ts . "|" . csrf_token(), subkey("bot"));

    return '<input type="hidden" name="_bt" value="' . $ts . "." . $sig . '">'
        . '<div class="hp" aria-hidden="true"><label>ปล่อยว่างไว้ <input type="text" name="hp_site" tabindex="-1" autocomplete="off" value=""></label></div>';
}

function bot_ok(): bool
{
    if (za_test_mode()) {
        return true;
    }

    if (($_POST["hp_site"] ?? "") !== "") {
        return false;
    }

    if (!preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', (string)($_POST["_bt"] ?? ""), $m)) {
        return false;
    }

    $expected = hash_hmac("sha256", $m[1] . "|" . ($_SESSION["csrf_token"] ?? ""), subkey("bot"));

    if (!hash_equals($expected, $m[2])) {
        return false;
    }

    $age = time() - (int)$m[1];

    return $age >= BOT_MIN_SECONDS && $age <= BOT_MAX_SECONDS;
}

const BOT_MESSAGE = "ส่งข้อมูลเร็วเกินไปหรือหน้าเว็บหมดอายุ กรุณาลองใหม่อีกครั้ง";

/* ======================================================================
   TOTP (RFC 6238) — ยืนยันตัวตนสองขั้นตอนด้วยแอป Google/Microsoft Authenticator
   ====================================================================== */

const BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

function base32_encode_bytes(string $bytes): string
{
    $bits = "";
    foreach (str_split($bytes) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, "0", STR_PAD_LEFT);
    }

    $out = "";
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, "0"))];
    }

    return $out;
}

function base32_decode_string(string $text): string
{
    $bits = "";
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', "", $text))) as $char) {
        $pos = strpos(BASE32_ALPHABET, $char);
        $bits .= str_pad(decbin($pos), 5, "0", STR_PAD_LEFT);
    }

    $out = "";
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }

    return $out;
}

function totp_code(string $secretBytes, int $step): string
{
    $message = pack("N2", $step >> 32, $step & 0xFFFFFFFF);
    $hash    = hash_hmac("sha1", $message, $secretBytes, true);
    $offset  = ord($hash[19]) & 15;
    $number  = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

    return str_pad((string)($number % 1000000), 6, "0", STR_PAD_LEFT);
}

/** ตรวจรหัส 6 หลัก (เผื่อเวลาเครื่องเหลื่อม ±30 วินาที) และห้ามใช้รหัสของรอบที่เคยใช้แล้วซ้ำ */
function totp_verify(string $secretBytes, string $input, int $lastUsedStep, ?int &$matchedStep = null): bool
{
    $input = preg_replace('/\s+/', "", $input);

    if (!preg_match('/^\d{6}$/', $input)) {
        return false;
    }

    $now = intdiv(time(), 30);

    for ($drift = -1; $drift <= 1; $drift++) {
        $step = $now + $drift;

        if ($step > $lastUsedStep && hash_equals(totp_code($secretBytes, $step), $input)) {
            $matchedStep = $step;
            return true;
        }
    }

    return false;
}

function recovery_code_hash(string $code): string
{
    return hash_hmac("sha256", strtolower(preg_replace('/[^a-z0-9]/i', "", $code)), subkey("recovery"));
}

function generate_recovery_codes(int $count = 8): array
{
    $alphabet = "abcdefghjkmnpqrstuvwxyz23456789";
    $codes    = [];

    for ($i = 0; $i < $count; $i++) {
        $code = "";
        for ($j = 0; $j < 10; $j++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($code, 0, 5) . "-" . substr($code, 5);
    }

    return $codes;
}

/* ======================================================================
   รูปอัปโหลด: ตรวจแล้ว "วาดใหม่" ด้วย GD เพื่อล้าง EXIF/พิกัดและโค้ดที่ซ่อนในไฟล์
   ====================================================================== */

/**
 * @return string ชื่อไฟล์ใหม่ที่บันทึกในโฟลเดอร์ $dir
 * @throws RuntimeException ข้อความภาษาไทยสำหรับแสดงผู้ใช้
 */
function save_uploaded_image(array $file, string $dir, int $quality = 88, int $maxDimension = 4000): string
{
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("อัปโหลดรูปภาพไม่สำเร็จ");
    }
    if ((int)($file["size"] ?? 0) > MAX_UPLOAD_BYTES) {
        throw new RuntimeException("รูปภาพต้องมีขนาดไม่เกิน 2 MB");
    }
    if (!is_uploaded_file((string)$file["tmp_name"])) {
        throw new RuntimeException("อัปโหลดรูปภาพไม่สำเร็จ");
    }

    $types = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);

    if (!isset($types[$mime])) {
        throw new RuntimeException("รองรับเฉพาะ JPG, PNG และ WEBP");
    }

    $info = @getimagesize($file["tmp_name"]);

    if (!$info || ($info["mime"] ?? "") !== $mime) {
        throw new RuntimeException("ไฟล์รูปภาพไม่ถูกต้อง");
    }

    [$width, $height] = $info;

    if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || $width * $height > 16000000) {
        throw new RuntimeException("รูปภาพมีขนาดพิกเซลใหญ่เกินไป (ด้านยาวสุด {$maxDimension} พิกเซล)");
    }

    $image = @imagecreatefromstring((string)file_get_contents($file["tmp_name"]));

    if ($image === false) {
        throw new RuntimeException("ไม่สามารถอ่านไฟล์รูปภาพได้");
    }

    // รูปจากมือถือมักหมุนด้วย EXIF: หมุนให้ตรงก่อนล้างข้อมูลทิ้ง
    if ($mime === "image/jpeg" && function_exists("exif_read_data")) {
        $exif  = @exif_read_data($file["tmp_name"]);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif["Orientation"] ?? 1)] ?? 0;

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                $image = $rotated;
            }
        }
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $name = bin2hex(random_bytes(16)) . "." . $types[$mime];
    $temp = $dir . "." . $name . ".tmp";

    if ($mime === "image/png") {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $saved = imagepng($image, $temp, 6);
    } elseif ($mime === "image/webp") {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $saved = imagewebp($image, $temp, $quality);
    } else {
        $saved = imagejpeg($image, $temp, $quality);
    }

    if (!$saved || !@rename($temp, $dir . $name)) {
        @unlink($temp);
        throw new RuntimeException("ไม่สามารถบันทึกรูปภาพได้");
    }

    return $name;
}

security_bootstrap();
