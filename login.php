<?php
require_once __DIR__ . '/db.php';
start_session_once();

if (current_user()) {
    header('Location: index.php');
    exit;
}

$errors   = array();
$email    = '';
$okMessage = '';

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $okMessage = 'Registrace proběhla úspěšně. Přihlaš se!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = isset($_POST['email'])    ? trim($_POST['email'])         : '';
    $password = isset($_POST['password']) ? (string)$_POST['password']   : '';
    $remember = isset($_POST['remember']) ? 1 : 0;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Zadej platný e-mail.';
    }
    if ($password === '') {
        $errors[] = 'Zadej heslo.';
    }

    if (empty($errors)) {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT id, name, email, password_hash, is_admin FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(array(':email' => $email));
        $u = $stmt->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) {
            $errors[] = 'Neplatný e-mail nebo heslo.';
        } else {
            $_SESSION['user'] = array(
                    'id'       => (int)$u['id'],
                    'name'     => $u['name'],
                    'email'    => $u['email'],
                    'is_admin' => (int)$u['is_admin'],
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
    <title>Přihlášení – Sticky Kontakt</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="auth-logo-icon">📌</div>
            <h1>Sticky Kontakt</h1>
            <p>Přihlaš se ke svému účtu</p>
        </div>

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
            <div class="field" style="margin-bottom:12px;">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       value="<?php echo h($email); ?>"
                       placeholder="tvuj@email.cz" required>
            </div>
            <div class="field" style="margin-bottom:12px;">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password"
                       placeholder="••••••••" required>
            </div>
            <div class="field" style="margin-bottom:16px;">
                <label style="text-transform:none;letter-spacing:0;font-weight:400;font-size:13px;flex-direction:row;align-items:center;gap:8px;display:flex;cursor:pointer;">
                    <input type="checkbox" name="remember" value="1">
                    Zůstat přihlášený po dobu 30 dní
                </label>
            </div>
            <button class="btn" type="submit">Přihlásit se →</button>
        </form>

        <div class="auth-footer">
            Nemáš účet? <a href="register.php">Zaregistruj se</a>
        </div>
    </div>
</div>
</body>
</html>