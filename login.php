<?php
require_once __DIR__ . '/db.php';
start_session_once();

if (current_user()) {
    header('Location: index.php');
    exit;
}

$errors = array();
$email = '';

$okMessage = '';
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $okMessage = 'Registrace proběhla. Teď se přihlas.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $remember = isset($_POST['remember']) ? 1 : 0;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Zadej platný e-mail.';
    }
    if ($password === '') {
        $errors[] = 'Zadej heslo.';
    }

    if (empty($errors)) {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(array(':email' => $email));
        $u = $stmt->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) {
            $errors[] = 'Neplatný e-mail nebo heslo.';
        } else {
            $_SESSION['user'] = array(
                    'id' => (int)$u['id'],
                    'name' => $u['name'],
                    'email' => $u['email']
            );

            if ($remember === 1) {
                remember_me_set((int)$u['id']);
            }

            header('Location: index.php');
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
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="wrap topbar">
        <div><strong>Sticky Kontakt</strong> · Login</div>
        <div><a href="register.php">Registrace</a></div>
    </div>
</header>

<div class="wrap">
    <div class="panel">
        <?php if ($okMessage !== ''): ?>
            <div class="msg-ok"><?php echo h($okMessage); ?></div>
        <?php endif; ?>

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

        <form method="post" action="login.php">
            <div class="row">
                <div class="field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" value="<?php echo h($email); ?>" required>
                </div>
                <div class="field">
                    <label for="password">Heslo</label>
                    <input type="password" id="password" name="password" required>
                </div>
            </div>

            <div class="row" style="margin-top:10px;">
                <div class="field">
                    <label>
                        <input type="checkbox" name="remember" value="1">
                        Zůstat přihlášený
                    </label>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button class="btn" type="submit">Přihlásit</button>
            </div>
        </form>
    </div>
</div>

<div class="footer">POST login · session + remember cookie</div>
</body>
</html>