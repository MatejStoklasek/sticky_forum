<?php
require_once __DIR__ . '/db.php';
start_session_once();

$errors = array();
$name   = '';
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = isset($_POST['name'])     ? trim($_POST['name'])         : '';
    $email    = isset($_POST['email'])    ? trim($_POST['email'])        : '';
    $password = isset($_POST['password']) ? (string)$_POST['password']  : '';
    $agree    = isset($_POST['agree'])    ? 1 : 0;

    if ($name === '' || strlen($name) < 2) {
        $errors[] = 'Jméno musí mít alespoň 2 znaky.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Zadej platný e-mail.';
    }
    if ($password === '' || strlen($password) < 6) {
        $errors[] = 'Heslo musí mít alespoň 6 znaků.';
    }
    if ($agree !== 1) {
        $errors[] = 'Musíš souhlasit s podmínkami.';
    }

    if (empty($errors)) {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(array(':email' => $email));
        if ($stmt->fetch()) {
            $errors[] = 'Účet s tímto e-mailem už existuje.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password_hash, created_at)
                 VALUES (:name, :email, :hash, :created_at)"
            );
            $stmt->execute(array(
                    ':name'       => $name,
                    ':email'      => $email,
                    ':hash'       => $hash,
                    ':created_at' => date('Y-m-d H:i:s'),
            ));
            header('Location: login.php?registered=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrace – Sticky Kontakt</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="auth-logo-icon">📌</div>
            <h1>Sticky Kontakt</h1>
            <p>Vytvoř si nový účet</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="msg-err">
                <strong>Oprav prosím chyby:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo h($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php">
            <div class="field" style="margin-bottom:12px;">
                <label for="name">Jméno</label>
                <input type="text" id="name" name="name"
                       value="<?php echo h($name); ?>"
                       placeholder="Jan Novák" required>
            </div>
            <div class="field" style="margin-bottom:12px;">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       value="<?php echo h($email); ?>"
                       placeholder="tvuj@email.cz" required>
            </div>
            <div class="field" style="margin-bottom:12px;">
                <label for="password">Heslo <span style="font-weight:400;text-transform:none;letter-spacing:0;">(min. 6 znaků)</span></label>
                <input type="password" id="password" name="password"
                       placeholder="••••••••" required>
            </div>
            <div class="field" style="margin-bottom:16px;">
                <label style="text-transform:none;letter-spacing:0;font-weight:400;font-size:13px;flex-direction:row;align-items:center;gap:8px;display:flex;cursor:pointer;">
                    <input type="checkbox" name="agree" value="1" required>
                    Souhlasím s pravidly a podmínkami
                </label>
            </div>
            <button class="btn" type="submit">Vytvořit účet →</button>
        </form>

        <div class="auth-footer">
            Už máš účet? <a href="login.php">Přihlaš se</a>
        </div>
    </div>
</div>
</body>
</html>