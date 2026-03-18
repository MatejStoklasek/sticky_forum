<?php
require_once __DIR__ . '/db.php';
require_login();
start_session_once();

$user    = current_user();
$isAdmin = !empty($user['is_admin']);

$errors    = array();
$okMessage = '';

/* Form defaults */
$message    = '';
$urgency    = '';
$color      = 'yellow';
$newsletter = 0;
$agree      = 0;
$topicPost  = '';
$fullName   = isset($user['name'])  ? $user['name']  : '';
$email      = isset($user['email']) ? $user['email'] : '';

/* GET: filter / search */
$topic = isset($_GET['topic']) ? trim($_GET['topic']) : '';
$q     = isset($_GET['q'])     ? trim($_GET['q'])     : '';
$view  = isset($_GET['view'])  ? trim($_GET['view'])  : '';

$allowedTopics = array('', 'spoluprace', 'reklamace', 'dotaz', 'jine');
if (!in_array($topic, $allowedTopics, true)) { $topic = ''; }
if (!in_array($view, array('', 'trash'), true)) { $view = ''; }

$MESSAGE_MAX = 600;

/* ── POST actions ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_post()) {
        $errors[] = 'Neplatný formulář. Obnov stránku a zkus to znovu.';
    } else {
        $action    = isset($_POST['action'])     ? trim((string)$_POST['action']) : '';
        $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id']      : 0;
        $pdo       = db();

        /* Returns message row if current user owns it, false otherwise */
        $ownMessage = function(int $id) use ($pdo, $user) {
            $s = $pdo->prepare("SELECT id, user_id FROM messages WHERE id = :id LIMIT 1");
            $s->execute(array(':id' => $id));
            $row = $s->fetch();
            if (!$row) { return false; }
            return ((int)$row['user_id'] === (int)$user['id']) ? $row : false;
        };

        /* ── Trash ── own or admin ── */
        if ($action === 'trash_message' && $messageId > 0) {
            if ($isAdmin || $ownMessage($messageId)) {
                $s = $pdo->prepare(
                        "INSERT INTO message_trash (user_id, message_id, trashed_at)
                     VALUES (:uid, :mid, :ts)
                     ON DUPLICATE KEY UPDATE trashed_at = VALUES(trashed_at)"
                );
                $s->execute(array(':uid' => (int)$user['id'], ':mid' => $messageId, ':ts' => date('Y-m-d H:i:s')));
            }
            header('Location: index.php');
            exit;
        }

        /* ── Restore ── own or admin ── */
        if ($action === 'restore_message' && $messageId > 0) {
            if ($isAdmin) {
                $s = $pdo->prepare("DELETE FROM message_trash WHERE message_id = :mid");
                $s->execute(array(':mid' => $messageId));
            } else {
                $s = $pdo->prepare("DELETE FROM message_trash WHERE user_id = :uid AND message_id = :mid");
                $s->execute(array(':uid' => (int)$user['id'], ':mid' => $messageId));
            }
            header('Location: index.php?view=trash');
            exit;
        }

        /* ── Permanent delete ── own (from trash) or admin ── */
        if ($action === 'delete_message' && $messageId > 0) {
            if ($isAdmin || $ownMessage($messageId)) {
                $s = $pdo->prepare("DELETE FROM messages WHERE id = :id");
                $s->execute(array(':id' => $messageId));
            }
            header('Location: index.php?view=trash&deleted=1');
            exit;
        }

        /* ── New message ── */
        if ($action === '' || $action === 'create_message') {
            $fullName   = isset($_POST['full_name'])  ? trim($_POST['full_name']) : '';
            $email      = isset($_POST['email'])      ? trim($_POST['email'])     : '';
            $message    = isset($_POST['message'])    ? trim($_POST['message'])   : '';
            $topicPost  = isset($_POST['topic'])      ? trim($_POST['topic'])     : '';
            $urgency    = isset($_POST['urgency'])    ? trim($_POST['urgency'])   : '';
            $color      = isset($_POST['color'])      ? trim($_POST['color'])     : 'yellow';
            $newsletter = isset($_POST['newsletter']) ? 1 : 0;
            $agree      = isset($_POST['agree'])      ? 1 : 0;

            $allowedTopicPost = array('spoluprace', 'reklamace', 'dotaz', 'jine');
            $allowedUrgency   = array('nizka', 'stredni', 'vysoka');
            $allowedColors    = array('yellow', 'pink', 'green', 'blue');

            if ($fullName === '' || strlen($fullName) < 2)           { $errors[] = 'Jméno musí mít alespoň 2 znaky.'; }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Zadej platný e-mail.'; }
            if ($message === '' || strlen($message) < 10)            { $errors[] = 'Zpráva musí mít alespoň 10 znaků.'; }
            if (strlen($message) > $MESSAGE_MAX)                     { $errors[] = 'Zpráva je moc dlouhá (max ' . (int)$MESSAGE_MAX . ' znaků).'; }
            if (!in_array($topicPost, $allowedTopicPost, true))      { $errors[] = 'Vyber téma.'; }
            if (!in_array($urgency,   $allowedUrgency,  true))       { $errors[] = 'Vyber prioritu.'; }
            if (!in_array($color,     $allowedColors,   true))       { $errors[] = 'Neplatná barva.'; }
            if ($agree !== 1)                                         { $errors[] = 'Musíš zaškrtnout souhlas se zpracováním.'; }

            if (empty($errors)) {
                $s = $pdo->prepare(
                        "INSERT INTO messages
                     (user_id, full_name, email, topic, urgency, color, newsletter, agree, message, created_at)
                     VALUES (:user_id,:full_name,:email,:topic,:urgency,:color,:newsletter,:agree,:message,:created_at)"
                );
                $s->execute(array(
                        ':user_id'    => (int)$user['id'],
                        ':full_name'  => $fullName,
                        ':email'      => $email,
                        ':topic'      => $topicPost,
                        ':urgency'    => $urgency,
                        ':color'      => $color,
                        ':newsletter' => $newsletter,
                        ':agree'      => $agree,
                        ':message'    => $message,
                        ':created_at' => date('Y-m-d H:i:s'),
                ));
                header('Location: index.php?sent=1');
                exit;
            }
        }
    }
}

if (isset($_GET['sent'])    && $_GET['sent']    === '1') { $okMessage = 'Zpráva připnuta na tabuli! 📌'; }
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') { $okMessage = 'Zpráva byla trvale smazána.'; }
$isJustSent = (isset($_GET['sent']) && $_GET['sent'] === '1');

$pdo = db();

/* Trash count */
if ($isAdmin) {
    $tRow = $pdo->query("SELECT COUNT(DISTINCT message_id) AS c FROM message_trash")->fetch();
} else {
    $ts = $pdo->prepare("SELECT COUNT(*) AS c FROM message_trash WHERE user_id = :uid");
    $ts->execute(array(':uid' => (int)$user['id']));
    $tRow = $ts->fetch();
}
$trashCount = $tRow ? (int)$tRow['c'] : 0;

/* ── Load messages ─────────────────────────────────────────── */
$where  = array();
$params = array();

if ($topic !== '') {
    $where[]          = 'm.topic = :topic';
    $params[':topic'] = $topic;
}
if ($q !== '') {
    $where[]       = '(m.full_name LIKE :q1 OR m.email LIKE :q2 OR m.message LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}

if ($view === 'trash') {
    if ($isAdmin) {
        $where[] = 'EXISTS (SELECT 1 FROM message_trash mt WHERE mt.message_id = m.id)';
    } else {
        $where[]              = 'EXISTS (SELECT 1 FROM message_trash mt WHERE mt.user_id = :trash_uid AND mt.message_id = m.id)';
        $params[':trash_uid'] = (int)$user['id'];
    }
} else {
    if ($isAdmin) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM message_trash mt WHERE mt.message_id = m.id)';
    } else {
        $where[]              = 'NOT EXISTS (SELECT 1 FROM message_trash mt WHERE mt.user_id = :trash_uid AND mt.message_id = m.id)';
        $params[':trash_uid'] = (int)$user['id'];
    }
}

$sql = "SELECT m.id, m.user_id, m.full_name, m.email, m.topic, m.urgency,
               m.color, m.newsletter, m.created_at, m.message,
               u.name AS user_name
        FROM messages m
        JOIN users u ON u.id = m.user_id";
if (!empty($where)) { $sql .= " WHERE " . implode(' AND ', $where); }
$sql .= " ORDER BY m.created_at DESC LIMIT 60";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

$topicLabels  = array('spoluprace'=>'Spolupráce','reklamace'=>'Reklamace','dotaz'=>'Dotaz','jine'=>'Jiné');
$urgencyIcons = array('nizka'=>'🟢','stredni'=>'🟡','vysoka'=>'🔴');
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sticky Kontakt<?php echo $isAdmin ? ' · Admin' : ''; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <div class="wrap topbar">
        <div class="brand">
            <div class="brand-icon">📌</div>
            Sticky Kontakt
            <?php if ($isAdmin): ?><span class="admin-badge">ADMIN</span><?php endif; ?>
        </div>
        <div class="topbar-actions">
            <a class="<?php echo ($view===''     ?'is-active':''); ?>" href="index.php">📋 Tabule</a>
            <a class="<?php echo ($view==='trash'?'is-active':''); ?>" href="index.php?view=trash">
                🗑 Koš<?php echo ($trashCount > 0 ? ' (' . (int)$trashCount . ')' : ''); ?>
            </a>
            <span style="color:rgba(255,255,255,.25);">|</span>
            <span style="color:rgba(255,255,255,.50);font-size:13px;">
                <?php echo $isAdmin ? '👑' : '👤'; ?> <?php echo h($user['name']); ?>
            </span>
            <a class="logout" href="logout.php">Odhlásit</a>
        </div>
    </div>
</header>

<div class="wrap">
    <div class="grid">

        <!-- ── Left panel ──────────────────── -->
        <div class="panel">

            <div class="panel-title">🔍 Filtr zpráv</div>
            <form method="get" action="index.php">
                <input type="hidden" name="view" value="<?php echo h($view); ?>">
                <div class="filter-bar">
                    <div class="field">
                        <label for="q">Hledat</label>
                        <input type="text" id="q" name="q"
                               value="<?php echo h($q); ?>"
                               placeholder="jméno, e-mail, text…">
                    </div>
                    <div class="field" style="max-width:155px;">
                        <label for="tf">Téma</label>
                        <select id="tf" name="topic">
                            <option value="">Všechna</option>
                            <option value="spoluprace" <?php echo ($topic==='spoluprace'?'selected':''); ?>>Spolupráce</option>
                            <option value="reklamace"  <?php echo ($topic==='reklamace' ?'selected':''); ?>>Reklamace</option>
                            <option value="dotaz"      <?php echo ($topic==='dotaz'     ?'selected':''); ?>>Dotaz</option>
                            <option value="jine"       <?php echo ($topic==='jine'      ?'selected':''); ?>>Jiné</option>
                        </select>
                    </div>
                    <button class="btn secondary" type="submit" style="align-self:flex-end;">Filtrovat</button>
                </div>
            </form>

            <?php if ($view !== 'trash'): ?>
                <hr class="divider">
                <div class="panel-title">✏️ Nová zpráva</div>

                <?php if ($okMessage !== ''): ?>
                    <div class="msg-ok"><?php echo h($okMessage); ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="msg-err">
                        <strong>Oprav prosím chyby:</strong>
                        <ul><?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="index.php">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_message">

                    <div class="field" style="margin-bottom:10px;">
                        <label for="full_name">Jméno *</label>
                        <input type="text" id="full_name" name="full_name"
                               value="<?php echo h($fullName); ?>" placeholder="Tvoje jméno" required>
                    </div>
                    <div class="field" style="margin-bottom:10px;">
                        <label for="em">E-mail *</label>
                        <input type="email" id="em" name="email"
                               value="<?php echo h($email); ?>" placeholder="tvuj@email.cz" required>
                    </div>
                    <div class="field" style="margin-bottom:10px;">
                        <label for="tp">Téma *</label>
                        <select id="tp" name="topic" required>
                            <option value="">Vyber téma…</option>
                            <option value="spoluprace" <?php echo ($topicPost==='spoluprace'?'selected':''); ?>>Spolupráce</option>
                            <option value="reklamace"  <?php echo ($topicPost==='reklamace' ?'selected':''); ?>>Reklamace</option>
                            <option value="dotaz"      <?php echo ($topicPost==='dotaz'     ?'selected':''); ?>>Dotaz</option>
                            <option value="jine"       <?php echo ($topicPost==='jine'      ?'selected':''); ?>>Jiné</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:10px;">
                        <label for="msg">Zpráva * <span style="font-weight:400;text-transform:none;letter-spacing:0;">(min. 10 znaků)</span></label>
                        <textarea id="msg" name="message"
                                  maxlength="<?php echo (int)$MESSAGE_MAX; ?>"
                                  placeholder="Napiš svou zprávu…"
                                  required><?php echo h($message); ?></textarea>
                        <div class="hint">Max <?php echo (int)$MESSAGE_MAX; ?> znaků.</div>
                    </div>
                    <div class="field" style="margin-bottom:10px;">
                        <label>Priorita *</label>
                        <div class="inline">
                            <label><input type="radio" name="urgency" value="nizka"   <?php echo ($urgency==='nizka'  ?'checked':''); ?> required> 🟢 Nízká</label>
                            <label><input type="radio" name="urgency" value="stredni" <?php echo ($urgency==='stredni'?'checked':''); ?>> 🟡 Střední</label>
                            <label><input type="radio" name="urgency" value="vysoka"  <?php echo ($urgency==='vysoka' ?'checked':''); ?>> 🔴 Vysoká</label>
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:10px;">
                        <label for="col">Barva sticky</label>
                        <select id="col" name="color">
                            <option value="yellow" <?php echo ($color==='yellow'?'selected':''); ?>>🟡 Žlutá</option>
                            <option value="pink"   <?php echo ($color==='pink'  ?'selected':''); ?>>🩷 Růžová</option>
                            <option value="green"  <?php echo ($color==='green' ?'selected':''); ?>>🟢 Zelená</option>
                            <option value="blue"   <?php echo ($color==='blue'  ?'selected':''); ?>>🔵 Modrá</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:14px;">
                        <label>Souhlasy</label>
                        <div class="inline" style="flex-direction:column;align-items:flex-start;gap:8px;">
                            <label><input type="checkbox" name="newsletter" value="1" <?php echo ($newsletter?'checked':''); ?>> Chci dostávat newsletter</label>
                            <label><input type="checkbox" name="agree" value="1" <?php echo ($agree?'checked':''); ?> required> Souhlasím se zpracováním údajů *</label>
                        </div>
                    </div>
                    <button class="btn" type="submit">📌 Připnout zprávu</button>
                </form>

            <?php else: ?>
                <?php if ($okMessage !== ''): ?>
                    <div class="msg-ok"><?php echo h($okMessage); ?></div>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <div class="admin-info-box">
                        <strong>👑 Admin pohled</strong><br>
                        Vidíš všechny odlepené zprávy od všech uživatelů.
                        Můžeš je obnovit nebo trvale smazat.
                    </div>
                <?php else: ?>
                    <p style="color:var(--muted);font-size:13px;margin-top:8px;">
                        Tady jsou zprávy, které jsi odlepil(a) z tabule.
                        Ostatním jsou stále viditelné.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

        </div><!-- /panel -->

        <!-- ── Cork board ──────────────────── -->
        <div class="board">
            <div class="board-header">
                <div class="board-label">
                    <?php echo $view === 'trash'
                            ? '🗑 Koš' . ($isAdmin ? ' – všichni uživatelé' : '')
                            : '📌 Tabule zpráv'; ?>
                </div>
                <div style="font-size:12px;color:rgba(255,255,255,.50);">
                    <?php $c = count($messages); echo $c . ' ' . ($c===1?'zpráva':($c<5?'zprávy':'zpráv')); ?>
                </div>
            </div>

            <div class="notes">
                <?php if ($view === 'trash' && empty($messages)): ?>
                    <div class="note blue info-note" style="--rot:-1;">
                        <div class="pin"></div>
                        <h3>ℹ️ Koš je prázdný</h3>
                        <div class="body">Žádné odlepené zprávy.</div>
                    </div>
                <?php elseif ($view !== 'trash' && empty($messages)): ?>
                    <div class="note yellow info-note" style="--rot:1;">
                        <div class="pin"></div>
                        <h3>📭 Prázdná tabule</h3>
                        <div class="body"><?php echo ($q!==''||$topic!=='')?'Žádné zprávy nevyhovují filtru.':'Zatím žádné zprávy. Buď první!'; ?></div>
                    </div>
                <?php endif; ?>

                <?php
                $rotations = array(-2.5,-1.5,-0.5,0.5,1.5,2.5,-1,1,-2,2);
                $delay = 0;
                foreach ($messages as $i => $msg):
                    $delay  += 60;
                    $rot     = $rotations[$i % count($rotations)];
                    $newCls  = ($isJustSent && $i===0) ? ' is-new' : '';
                    $tLabel  = isset($topicLabels[$msg['topic']]) ? $topicLabels[$msg['topic']] : h($msg['topic']);
                    $urgIcon = isset($urgencyIcons[$msg['urgency']]) ? $urgencyIcons[$msg['urgency']] : '';
                    $isOwn   = ((int)$msg['user_id'] === (int)$user['id']);
                    $canAct  = $isOwn || $isAdmin;
                    ?>
                    <div class="note <?php echo h($msg['color']); ?><?php echo $newCls; ?>"
                         style="--rot:<?php echo $rot; ?>;--delay:<?php echo $delay; ?>ms;">
                        <div class="pin"></div>

                        <h3>
                            <?php echo h($msg['full_name']); ?>
                            <span class="tag"><?php echo $tLabel; ?></span>
                            <?php if ($isAdmin && !$isOwn): ?>
                                <span class="tag" style="background:rgba(217,119,6,.15);color:#92400e;border-color:rgba(217,119,6,.3);">
                                <?php echo h($msg['user_name']); ?>
                            </span>
                            <?php endif; ?>
                        </h3>
                        <div class="meta">
                            <?php echo date('j.n.Y H:i', strtotime($msg['created_at'])); ?>
                            · <?php echo $urgIcon; ?> <?php echo h($msg['urgency']); ?>
                            <?php if ($msg['newsletter']): ?> · 📧<?php endif; ?>
                        </div>
                        <div class="body"><?php
                            $text = h($msg['message']);
                            echo (mb_strlen($text) > 180) ? mb_substr($text, 0, 180) . '…' : $text;
                            ?></div>

                        <?php if ($canAct): ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
                                <?php if ($view === 'trash'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token"  value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action"      value="restore_message">
                                        <input type="hidden" name="message_id"  value="<?php echo (int)$msg['id']; ?>">
                                        <button type="submit" class="note-btn">📌 Obnovit</button>
                                    </form>
                                    <form method="post"
                                          onsubmit="return confirm('Opravdu smazat tuto zprávu natrvalo? Nejde to vrátit.');">
                                        <input type="hidden" name="csrf_token"  value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action"      value="delete_message">
                                        <input type="hidden" name="message_id"  value="<?php echo (int)$msg['id']; ?>">
                                        <button type="submit" class="note-btn danger">🗑 Smazat natrvalo</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token"  value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action"      value="trash_message">
                                        <input type="hidden" name="message_id"  value="<?php echo (int)$msg['id']; ?>">
                                        <button type="submit" class="note-btn">🗑 Odlepit</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<div class="footer">Sticky Kontakt · PHP + MySQL · XAMPP</div>
</body>
</html>