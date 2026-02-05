<?php
require_once __DIR__ . '/db.php';
start_session_once();

$errors = array();
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $agree = isset($_POST['agree']) ? 1 : 0;

    if ($name === '' || strlen($name) < 2) {
        $errors[] = 'Jméno musí mít alespoň 2 znaky.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Zadej platný e-mail.';
    }
    if ($password === '' || strlen($password) < 6) {
        $errors[] = 'Heslo je povinné a musí mít alespoň 6 znaků.';
    }
    if ($agree !== 1) {
        $errors[] = 'Musíš souhlasit s pravidly.';
    }

    if (empty($errors)) {
        $pdo = db();

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(array(':email' => $email));
        $exists = $stmt->fetch();

        if ($exists) {
            $errors[] = 'Účet s tímto e-mailem už existuje.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password_hash, created_at)
                 VALUES (:name, :email, :hash, :created_at)"
            );
            $stmt->execute(array(
                    ':name' => $name,
                    ':email' => $email,
                    ':hash' => $hash,
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
    <title>Registrace</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="wrap topbar">
        <div><strong>Sticky Kontakt</strong> · Registrace</div>
        <div><a href="login.php">Mám účet</a></div>
    </div>
</header>

<div class="wrap">
    <div class="panel">
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
            <div class="row">
                <div class="field">
                    <label for="name">Jméno (text)</label>
                    <input type="text" id="name" name="name" value="<?php echo h($name); ?>" required>
                </div>
                <div class="field">
                    <label for="email">E-mail (email)</label>
                    <input type="email" id="email" name="email" value="<?php echo h($email); ?>" required>
                </div>
                <div class="field">
                    <label for="password">Heslo (password)</label>
                    <input type="password" id="password" name="password" required>
                    <div class="hint">Heslo ukládáme bezpečně jako hash.</div>
                </div>
            </div>

            <div class="row" style="margin-top:10px;">
                <div class="field">
                    <label>
                        <input type="checkbox" name="agree" value="1">
                        Souhlasím s pravidly (checkbox)
                    </label>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button class="btn" type="submit">Vytvořit účet</button>
                <a class="btn secondary" href="login.php" style="text-decoration:none; display:inline-block;">Zpět na login</a>
            </div>
        </form>
    </div>
</div>

<div class="footer">PHP 5.6 · MySQL · XAMPP</div>
</body>
</html>