<?php
require_once __DIR__ . '/db.php';
require_login();
start_session_once();

$user = current_user();

$errors = array();
$okMessage = '';

/* Defaulty pro formulář (aby na GET nebyly undefined) */
$message = '';
$urgency = '';
$color = 'yellow';
$newsletter = 0;
$agree = 0;
$topicPost = '';

/* GET: filtr/hledání */
$topic = isset($_GET['topic']) ? trim($_GET['topic']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$view = isset($_GET['view']) ? trim($_GET['view']) : ''; // '' | 'trash'

$allowedTopics = array('', 'spoluprace', 'reklamace', 'dotaz', 'jine');
if (!in_array($topic, $allowedTopics, true)) {
    $topic = '';
}
if (!in_array($view, array('', 'trash'), true)) {
    $view = '';
}

/* POST: akce (trash/restore) + odeslání zprávy */
$MESSAGE_MAX = 600;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_post()) {
        $errors[] = 'Neplatný formulář. Obnov stránku a zkus to znovu.';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

        // 1) Odlepení do koše (jen pro aktuálního uživatele)
        if ($action === 'trash_message') {
            $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            if ($messageId > 0) {
                $pdo = db();

                $stmt = $pdo->prepare(
                        "INSERT INTO message_trash (user_id, message_id, trashed_at)
                     VALUES (:uid, :mid, :ts)
                     ON DUPLICATE KEY UPDATE trashed_at = VALUES(trashed_at)"
                );
                $stmt->execute(array(
                        ':uid' => (int)$user['id'],
                        ':mid' => $messageId,
                        ':ts'  => date('Y-m-d H:i:s'),
                ));

                header('Location: index.php' . ($view === 'trash' ? '?view=trash' : ''));
                exit;
            }
        }

        // 2) Obnovení z koše
        if ($action === 'restore_message') {
            $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            if ($messageId > 0) {
                $pdo = db();
                $stmt = $pdo->prepare("DELETE FROM message_trash WHERE user_id = :uid AND message_id = :mid");
                $stmt->execute(array(
                        ':uid' => (int)$user['id'],
                        ':mid' => $messageId,
                ));

                header('Location: index.php?view=trash');
                exit;
            }
        }

        // 3) Odeslání nové zprávy (původní logika)
        if ($action === '' || $action === 'create_message') {
            // NEVYPISUJE se do formuláře, bere se z přihlášeného účtu
            $fullName = isset($user['name']) ? trim($user['name']) : '';
            $email = isset($user['email']) ? trim($user['email']) : '';

            $message = isset($_POST['message']) ? trim($_POST['message']) : '';
            $topicPost = isset($_POST['topic']) ? trim($_POST['topic']) : '';
            $urgency = isset($_POST['urgency']) ? trim($_POST['urgency']) : '';
            $color = isset($_POST['color']) ? trim($_POST['color']) : 'yellow';

            $newsletter = isset($_POST['newsletter']) ? 1 : 0;
            $agree = isset($_POST['agree']) ? 1 : 0;

            //Validace + ulozeni do DB
            $allowedTopicPost = array('spoluprace', 'reklamace', 'dotaz', 'jine');
            $allowedUrgency = array('nizka', 'stredni', 'vysoka');
            $allowedColors = array('yellow','pink','green','blue');

            if ($fullName === '' || strlen($fullName) < 2) {
                $errors[] = 'Chybí jméno v profilu uživatele.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Chybí platný e-mail v profilu uživatele.';
            }
            if ($message === '' || strlen($message) < 10) {
                $errors[] = 'Zpráva musí mít alespoň 10 znaků.';
            }
            if (strlen($message) > $MESSAGE_MAX) {
                $errors[] = 'Zpráva je moc dlouhá. Maximum je ' . (int)$MESSAGE_MAX . ' znaků.';
            }
            if (!in_array($topicPost, $allowedTopicPost, true)) {
                $errors[] = 'Vyber téma.';
            }
            if (!in_array($urgency, $allowedUrgency, true)) {
                $errors[] = 'Vyber prioritu.';
            }
            if (!in_array($color, $allowedColors, true)) {
                $errors[] = 'Neplatná barva sticky note.';
            }
            if ($agree !== 1) {
                $errors[] = 'Musíš zaškrtnout souhlas se zpracováním.';
            }

            if (empty($errors)) {
                $pdo = db();
                $stmt = $pdo->prepare(
                        "INSERT INTO messages
                     (user_id, full_name, email, topic, urgency, color, newsletter, agree, message, created_at)
                     VALUES
                     (:user_id, :full_name, :email, :topic, :urgency, :color, :newsletter, :agree, :message, :created_at)"
                );
                $stmt->execute(array(
                        ':user_id' => (int)$user['id'],
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':topic' => $topicPost,
                        ':urgency' => $urgency,
                        ':color' => $color,
                        ':newsletter' => $newsletter,
                        ':agree' => $agree,
                        ':message' => $message,
                        ':created_at' => date('Y-m-d H:i:s'),
                ));

                header('Location: index.php?sent=1');
                exit;
            }
        }
    }
}

if (isset($_GET['sent']) && $_GET['sent'] === '1') {
    $okMessage = 'Zpráva byla uložena a připnuta na tabuli.';
}

$isJustSent = (isset($_GET['sent']) && $_GET['sent'] === '1');

$pdo = db();

// Počet v koši (jen pro badge/link)
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM message_trash WHERE user_id = :uid");
$stmt->execute(array(':uid' => (int)$user['id']));
$trashCountRow = $stmt->fetch();
$trashCount = $trashCountRow ? (int)$trashCountRow['c'] : 0;

/* Načtení zpráv */
$where = array();
$params = array();

if ($topic !== '') {
    $where[] = 'm.topic = :topic';
    $params[':topic'] = $topic;
}
if ($q !== '') {
    $where[] = '(m.full_name LIKE :q OR m.email LIKE :q OR m.message LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

// View: normál vs koš
if ($view === 'trash') {
    $where[] = 'EXISTS (SELECT 1 FROM message_trash mt WHERE mt.user_id = :trash_uid AND mt.message_id = m.id)';
    $params[':trash_uid'] = (int)$user['id'];
} else {
    $where[] = 'NOT EXISTS (SELECT 1 FROM message_trash mt WHERE mt.user_id = :trash_uid AND mt.message_id = m.id)';
    $params[':trash_uid'] = (int)$user['id'];
}

$sql = "SELECT m.id, m.full_name, m.email, m.topic, m.urgency, m.color, m.newsletter, m.created_at, m.message,
               u.name AS user_name
        FROM messages m
        JOIN users u ON u.id = m.user_id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY m.created_at DESC LIMIT 60";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sticky Kontakt – tabule</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="wrap topbar">
        <div>
            <strong>Sticky Kontakt</strong> · Přihlášen: <?php echo h($user['name']); ?>
        </div>
        <div class="topbar-actions">
            <a class="<?php echo ($view === '' ? 'is-active' : ''); ?>" href="index.php">Tabule</a>
            <a class="<?php echo ($view === 'trash' ? 'is-active' : ''); ?>" href="index.php?view=trash">
                Koš<?php echo ($trashCount > 0 ? ' (' . (int)$trashCount . ')' : ''); ?>
            </a>
            <a href="logout.php">Odhlásit</a>
        </div>
    </div>
</header>

<div class="wrap">
    <div class="grid">
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

            <!-- Filtr/hledání -->
            <form method="get" action="index.php">
                <input type="hidden" name="view" value="<?php echo h($view); ?>">
                <div class="row" style="align-items:flex-end;">
                    <div class="field">
                        <label for="q">Hledat</label>
                        <input type="text" id="q" name="q" value="<?php echo h($q); ?>" placeholder="hledat ve zprávách...">
                    </div>
                    <div class="field" style="max-width:260px;">
                        <label for="topic">Téma</label>
                        <select id="topic" name="topic">
                            <option value="" <?php echo ($topic === '' ? 'selected' : ''); ?>>Všechna</option>
                            <option value="spoluprace" <?php echo ($topic === 'spoluprace' ? 'selected' : ''); ?>>Spolupráce</option>
                            <option value="reklamace" <?php echo ($topic === 'reklamace' ? 'selected' : ''); ?>>Reklamace</option>
                            <option value="dotaz" <?php echo ($topic === 'dotaz' ? 'selected' : ''); ?>>Dotaz</option>
                            <option value="jine" <?php echo ($topic === 'jine' ? 'selected' : ''); ?>>Jiné</option>
                        </select>
                    </div>
                    <div class="field" style="flex:0; min-width:160px;">
                        <button class="btn secondary" type="submit">Filtrovat</button>
                    </div>
                </div>
            </form>

            <?php if ($view !== 'trash'): ?>
                <hr style="border:0;border-top:1px solid rgba(15,23,42,.12); margin: 14px 0;">

                <!-- Kontaktní formulář -->
                <form method="post" action="index.php">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_message">

                    <div class="row">
                        <div class="field" style="max-width:260px;">
                            <label for="topic_post">Téma</label>
                            <select id="topic_post" name="topic" required>
                                <option value="" <?php echo ($topicPost === '' ? 'selected' : ''); ?>>Vyber…</option>
                                <option value="spoluprace" <?php echo ($topicPost === 'spoluprace' ? 'selected' : ''); ?>>Spolupráce</option>
                                <option value="reklamace" <?php echo ($topicPost === 'reklamace' ? 'selected' : ''); ?>>Reklamace</option>
                                <option value="dotaz" <?php echo ($topicPost === 'dotaz' ? 'selected' : ''); ?>>Dotaz</option>
                                <option value="jine" <?php echo ($topicPost === 'jine' ? 'selected' : ''); ?>>Jiné</option>
                            </select>
                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <div class="field">
                            <label for="message">Zpráva</label>
                            <textarea id="message" name="message" maxlength="<?php echo (int)$MESSAGE_MAX; ?>" required><?php echo h($message); ?></textarea>
                            <div class="hint">Max <?php echo (int)$MESSAGE_MAX; ?> znaků.</div>
                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <div class="field" style="min-width:320px;">
                            <label>Priorita</label>
                            <div class="inline">
                                <label><input type="radio" name="urgency" value="nizka" <?php echo ($urgency === 'nizka' ? 'checked' : ''); ?>> Nízká</label>
                                <label><input type="radio" name="urgency" value="stredni" <?php echo ($urgency === 'stredni' ? 'checked' : ''); ?>> Střední</label>
                                <label><input type="radio" name="urgency" value="vysoka" <?php echo ($urgency === 'vysoka' ? 'checked' : ''); ?>> Vysoká</label>
                            </div>
                        </div>

                        <div class="field" style="max-width:260px;">
                            <label for="color">Barva sticky</label>
                            <select id="color" name="color">
                                <option value="yellow" <?php echo ($color === 'yellow' ? 'selected' : ''); ?>>Žlutá</option>
                                <option value="pink" <?php echo ($color === 'pink' ? 'selected' : ''); ?>>Růžová</option>
                                <option value="green" <?php echo ($color === 'green' ? 'selected' : ''); ?>>Zelená</option>
                                <option value="blue" <?php echo ($color === 'blue' ? 'selected' : ''); ?>>Modrá</option>
                            </select>
                        </div>

                        <div class="field" style="min-width:260px;">
                            <label>Souhlasy</label>
                            <div class="inline">
                                <label><input type="checkbox" name="newsletter" <?php echo ($newsletter ? 'checked' : ''); ?>> Chci newsletter</label>
                                <label><input type="checkbox" name="agree" <?php echo ($agree ? 'checked' : ''); ?>> Souhlasím se zpracováním</label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <button class="btn" type="submit">Odeslat</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="board">
            <div class="notes">
                <?php if ($view === 'trash' && empty($messages)): ?>
                    <!-- Informační sticky note v prázdném koši -->
                    <div class="note blue info-note" style="--rot:-1; --delay:0ms;">
                        <div class="pin"></div>
                        <h3>ℹ️ Informace <span class="tag">systém</span></h3>
                        <div class="meta">Koš · jen pro tebe</div>
                        <div class="body">Koš je jen pro tebe: co odlepíš, uvidíš tady. Ostatním to nezmizí a stále to vidí na tabuli.</div>
                    </div>
                <?php elseif ($view !== 'trash' && empty($messages)): ?>
                    <div style="grid-column: 1/-1; text-align:center; color:rgba(15,23,42,.7); padding:20px;">
                        <?php if ($q !== '' || $topic !== ''): ?>
                            Žádné zprávy nevyhovují filtru.
                        <?php else: ?>
                            Zatím žádné zprávy. Buď první!
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php
                $delay = 0;
                foreach ($messages as $i => $msg):
                    $delay += 80;
                    $rotations = array(-2, -1, 0, 1, 2, -1.5, 1.5);
                    $rot = $rotations[$i % count($rotations)];
                    $newClass = $isJustSent && $i === 0 ? ' is-new' : '';
                    ?>
                    <div class="note <?php echo h($msg['color']); ?><?php echo $newClass; ?>"
                         style="--rot:<?php echo $rot; ?>; --delay:<?php echo $delay; ?>ms;">
                        <div class="pin"></div>

                        <h3><?php echo h($msg['full_name']); ?> <span class="tag"><?php echo h($msg['topic']); ?></span></h3>
                        <div class="meta">
                            <?php echo date('j.n.Y H:i', strtotime($msg['created_at'])); ?>
                            · <?php echo h($msg['urgency']); ?>
                            <?php if ($msg['newsletter']): ?> · newsletter<?php endif; ?>
                        </div>
                        <div class="body"><?php
                            $text = h($msg['message']);
                            echo strlen($text) > 180 ? substr($text, 0, 180) . '…' : $text;
                            ?></div>

                        <?php if ($view === 'trash'): ?>
                            <form method="post" style="margin-top:8px;">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="restore_message">
                                <input type="hidden" name="message_id" value="<?php echo (int)$msg['id']; ?>">
                                <button type="submit" class="btn secondary" style="font-size:11px;padding:4px 8px;">Obnovit</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="margin-top:8px;">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="trash_message">
                                <input type="hidden" name="message_id" value="<?php echo (int)$msg['id']; ?>">
                                <button type="submit" class="btn secondary" style="font-size:11px;padding:4px 8px;">Odlepit</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="footer">Vytvořeno v PHP s MySQL</div>
</body>
</html>