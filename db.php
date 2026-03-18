<?php
declare(strict_types=1);

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = require __DIR__ . '/config.php';

    $host = $cfg['db_host'];
    $name = $cfg['db_name'];
    $user = $cfg['db_user'];
    $pass = $cfg['db_pass'];
    $charset = $cfg['db_charset'];

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    $options = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    );

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}

function h($s): string
{
    if ($s === null) {
        return '';
    }

    if (is_bool($s)) {
        $s = $s ? '1' : '0';
    } elseif (!is_string($s)) {
        $s = (string)$s;
    }

    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function start_session_once()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function require_login(): void
{
    start_session_once();

    // current_user() už umí session i remember_me auto-login
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}
function csrf_token(): string
{
    start_session_once();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_verify_post(): bool
{
    start_session_once();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }

    $t = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $s = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';

    return ($t !== '' && $s !== '' && hash_equals($s, $t));
}

// ... existing code ...

function remember_me_set($userId)
{
    $selector = substr(bin2hex(openssl_random_pseudo_bytes(8)), 0, 16);
    $token = bin2hex(openssl_random_pseudo_bytes(32));
    $tokenHash = hash('sha256', $token);

    $days = 30;
    $expiresTs = time() + ($days * 24 * 60 * 60);
    $expiresAt = date('Y-m-d H:i:s', $expiresTs);

    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO auth_tokens (user_id, selector, token_hash, expires_at, created_at)
         VALUES (:user_id, :selector, :token_hash, :expires_at, :created_at)"
    );
    $stmt->execute(array(
        ':user_id' => (int)$userId,
        ':selector' => $selector,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
        ':created_at' => date('Y-m-d H:i:s'),
    ));

    $cookieValue = $selector . ':' . $token;

    // localhost bez HTTPS: secure=false, ale HttpOnly=true
    setcookie('remember_me', $cookieValue, $expiresTs, '/', '', false, true);
}

function remember_me_clear()
{
    if (!empty($_COOKIE['remember_me'])) {
        $raw = (string)$_COOKIE['remember_me'];
        $parts = explode(':', $raw, 2);

        if (count($parts) === 2) {
            $selector = $parts[0];
            $pdo = db();
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE selector = :selector");
            $stmt->execute(array(':selector' => $selector));
        }
    }

    setcookie('remember_me', '', time() - 3600, '/', '', false, true);
}

function current_user()
{
    start_session_once();

    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }

    // Auto-login přes remember_me cookie
    if (empty($_COOKIE['remember_me'])) {
        return null;
    }

    $raw = (string)$_COOKIE['remember_me'];
    $parts = explode(':', $raw, 2);
    if (count($parts) !== 2) {
        return null;
    }

    $selector = $parts[0];
    $token = $parts[1];

    if ($selector === '' || $token === '') {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT t.user_id, t.token_hash, t.expires_at, u.id, u.name, u.email, u.is_admin
         FROM auth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.selector = :selector
         LIMIT 1"
    );
    $stmt->execute(array(':selector' => $selector));
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (strtotime($row['expires_at']) < time()) {
        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE selector = :selector");
        $stmt->execute(array(':selector' => $selector));
        return null;
    }

    $tokenHash = hash('sha256', $token);
    if (!hash_equals($row['token_hash'], $tokenHash)) {
        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE selector = :selector");
        $stmt->execute(array(':selector' => $selector));
        return null;
    }

    $_SESSION['user'] = array(
        'id'       => (int)$row['id'],
        'name'     => $row['name'],
        'email'    => $row['email'],
        'is_admin' => (int)$row['is_admin'],
    );

    return $_SESSION['user'];
}

function trashNote(PDO $pdo, int $userId, int $noteId): void
{
    $sql = <<<SQL
            UPDATE notes
            SET trashed_at = NOW(), updated_at = NOW()
            WHERE id = :id AND user_id = :user_id AND trashed_at IS NULL
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $noteId,
        ':user_id' => $userId,
    ]);
}