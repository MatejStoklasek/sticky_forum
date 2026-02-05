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
        $errors[] = 'Neplatný formulář (CSRF). Obnov stránku a zkus to znovu.';
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
                $errors[] = 'Chybí jméno v profilu uživatele (zkus se znovu přihlásit/registrovat).';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Chybí platný e-mail v profilu uživatele (zkus se znovu přihlásit/registrovat).';
            }
            if ($message === '' || strlen($message) < 10) {
                $errors[] = 'Zpráva musí mít alespoň 10 znaků.';
            }
            if (strlen($message) > $MESSAGE_MAX) {
                $errors[] = 'Zpráva je moc dlouhá. Maximum je ' . (int)$MESSAGE_MAX . ' znaků.';
            }
            if (!in_array($topicPost, $allowedTopicPost, true)) {
                $errors[] = 'Vyber téma (select).';
            }
            if (!in_array($urgency, $allowedUrgency, true)) {
                $errors[] = 'Vyber prioritu (radio).';
            }
            if (!in_array($color, $allowedColors, true)) {
                $errors[] = 'Neplatná barva sticky note.';
            }
            if ($agree !== 1) {
                $errors[] = 'Musíš zaškrtnout souhlas se zpracováním (checkbox).';
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
    $okMessage = 'Zpráva byla uložena do databáze a připnuta na tabuli.';
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
    <div class="hero">
        <h1>Kontaktní tabule jako sticky notes</h1>
        <p>
            <?php if ($view === 'trash'): ?>
                Koš je jen pro tebe: co odlepíš, uvidíš tady. Ostatním to nezmizí.
            <?php else: ?>
                Pošli zprávu (POST → DB) a hned se objeví na tabuli. Filtrování je přes GET.
            <?php endif; ?>
        </p>
    </div>

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

            <!-- GET filtr/hledání -->
            <form method="get" action="index.php">
                <input type="hidden" name="view" value="<?php echo h($view); ?>">
                <div class="row" style="align-items:flex-end;">
                    <div class="field">
                        <label for="q">Hledat (GET)</label>
                        <input type="text" id="q" name="q" value="<?php echo h($q); ?>" placeholder="hledat ve zprávách...">
                        <div class="hint">GET = mění se URL, nic se neukládá.</div>
                    </div>
                    <div class="field" style="max-width:260px;">
                        <label for="topic">Téma (GET filtr)</label>
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

                <!-- POST: kontaktní formulář -->
                <form method="post" action="index.php">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_message">

                    <div class="row">
                        <div class="field">
                            <label>Odesílatel</label>
                            <div class="hint">
                                Automaticky z účtu: <strong><?php echo h($user['name']); ?></strong>
                                (<?php echo h($user['email']); ?>)
                            </div>
                        </div>

                        <div class="field" style="max-width:260px;">
                            <label for="topic_post">Téma (select)</label>
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
                            <label for="message">Zpráva (textarea)</label>
                            <textarea id="message" name="message" maxlength="<?php echo (int)$MESSAGE_MAX; ?>" required><?php echo h($message); ?></textarea>
                            <div class="hint">Max <?php echo (int)$MESSAGE_MAX; ?> znaků. Dlouhé zprávy se na note navíc zkrátí (aby nepřetékaly).</div>
                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <div class="field" style="min-width:320px;">
                            <label>Priorita (radio)</label>
                            <div class="inline">
                                <label><input type="radio" name="urgency" value="nizka" <?php echo ($urgency === 'nizka' ? 'checked' : ''); ?>> Nízká</label>
                                <label><input type="radio" name="urgency" value="stredni" <?php echo ($urgency === 'stredni' ? 'checked' : ''); ?>> Střední</label>
                                <label><input type="radio" name="urgency" value="vysoka" <?php echo ($urgency === 'vysoka' ? 'checked' : ''); ?>> Vysoká</label>
                            </div>
                            <div class="hint">Radio = vybereš právě jednu možnost.</div>
                        </div>

                        <div class="field" style="max-width:260px;">
                            <label for="color">Barva sticky (select)</label>
                            <select id="color" name="color">
                                <option value="yellow" <?php echo ($color === 'yellow' ? 'selected' : ''); ?>>Žlutá</option>
                                <option value="pink" <?php echo ($color === 'pink' ? 'selected' : ''); ?>>Růžová</option>
                                <option value="green" <?php echo ($color === 'green' ? 'selected' : ''); ?>>Zelená</option>
                                <option value="blue" <?php echo ($color === 'blue' ? 'selected' : ''); ?>>Modrá</option>
                            </select>
                        </div>

                        <div class="field" style="min-width:260px;">
                            <label>Souhlasy (checkbox)</label>
                            <div class="inline">
                                <label><input type="checkbox" name="newsletter" <?php echo ($newsletter ? 'checked' : ''); ?>> Chci newsletter</label>
                                <label><input type="checkbox" name="agree" <?php echo ($agree ? 'checked' : ''); ?>> Souhlasím se zpracováním</label>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <button class="btn" type="submit">Odeslat (POST) a uložit do DB</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="hint">Tip: v koši můžeš notes obnovit.</div>
            <?php endif; ?>
        </div>

        <div class="board">
            <div class="notes">
                <?php if (empty($messages)): ?>
                    <div class="note yellow" style="--rot:-1; --dx: 0px; --dy: 0px; --delay: 0ms;">
                        <div class="pin"></div>
                        <h3><?php echo ($view === 'trash' ? 'Koš je prázdný' : 'Zatím nic na tabuli'); ?></h3>
                        <div class="meta"><?php echo ($view === 'trash' ? 'Nic jsi ještě neodlepil.' : 'Pošli první zprávu přes formulář.'); ?></div>
                        <div class="body"><?php echo ($view === 'trash' ? 'Odlepené notes se zobrazí tady jen pro tebe.' : 'Tip: zkus i GET filtr/hledání.'); ?></div>
                    </div>
                <?php else: ?>
                    <?php $i = 0; foreach ($messages as $m): ?>
                        <?php
                        $colorClass = $m['color'];
                        if (!in_array($colorClass, array('yellow','pink','green','blue'), true)) {
                            $colorClass = 'yellow';
                        }

                        $classes = 'note ' . $colorClass;
                        if ($isJustSent && $i === 0 && $view === '') {
                            $classes .= ' is-new';
                        }

                        $delay = $i * 45;

                        // “Nalepení jinak”: deterministicky podle ID
                        $seed = (int)$m['id'];
                        $rot = (($seed * 37) % 9) - 4;      // -4..+4 (číslo, stupně v CSS přes * 1deg)
                        $dx  = (($seed * 17) % 7) - 3;      // -3..+3 px
                        $dy  = (($seed * 29) % 7) - 3;      // -3..+3 px

                        $i++;
                        ?>
                        <div class="<?php echo h($classes); ?>"
                             style="--rot: <?php echo (int)$rot; ?>; --dx: <?php echo (int)$dx; ?>px; --dy: <?php echo (int)$dy; ?>px; --delay: <?php echo (int)$delay; ?>ms;">
                            <div class="pin"></div>

                            <div class="note-actions">
                                <form method="post" action="index.php<?php echo ($view === 'trash' ? '?view=trash' : ''); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                    <?php if ($view === 'trash'): ?>
                                        <input type="hidden" name="action" value="restore_message">
                                        <button class="iconbtn" type="submit" title="Obnovit z koše" aria-label="Obnovit z koše">
                                            ↩
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="trash_message">
                                        <button class="iconbtn" type="submit" title="Odlepit do koše (uvidíš jen ty)" aria-label="Odlepit do koše">
                                            🗑
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>

                            <h3><?php echo h($m['topic']); ?> <span class="tag"><?php echo h($m['urgency']); ?></span></h3>
                            <div class="meta">
                                <?php echo h($m['full_name']); ?> · <?php echo h($m['created_at']); ?>
                                <span class="tag">zapsal: <?php echo h($m['user_name']); ?></span>
                            </div>

                            <div class="body"><?php echo h($m['message']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="footer">
    Sticky Kontakt · limit zprávy + “nalepení” + hover + koš jen pro tebe
</div>
</body>
</html>