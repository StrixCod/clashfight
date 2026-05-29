<?php
/**
 * ╔═══════════════════════════════════════════════╗
 * ║  CLASH FIGHT — v2.6 (InfinityFree)               ║
 * ║  Single-file PHP app | SQLite                     ║
 * ║  Admin: ClashFightOfficiel / PapillonHcup2306     ║
 * ╚═══════════════════════════════════════════════╝
 */

// ════════════════════════════════════════
// CONFIGURATION
// ════════════════════════════════════════
define('MISTRAL_API_KEY', 'L9KyHhbmAEYTDmEOwIUcPCBkpWf6xeS3');
define('MISTRAL_MODEL',   'mistral-large-latest');
define('SQLITE_FILE',     __DIR__ . '/clashfight.db');
define('APP_NAME',        'Clash Fight');

define('ROUNDS_MIN',      10);
define('ROUNDS_MAX',      20);
define('ROUNDS_DEFAULT',  10);

define('BASE_WIN_POINTS', 100);
define('BASE_LOSE_POINTS', 20);
define('REPEAT_ENEMY_PENALTY', 30);
define('REPEAT_OWN_MULT', 0.5);
define('MATCHMAKING_GAP', 300);

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);
define('SESSION_LIFETIME', 86400);

define('ADMIN_USERNAME', 'ClashFightOfficiel');
define('ADMIN_PASSWORD', 'PapillonHcup2306');
define('ADMIN_EMAIL', 'admin@clashfight.gg');

// ════════════════════════════════════════
// SECURITY HEADERS
// ════════════════════════════════════════
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ════════════════════════════════════════
// SESSION
// ════════════════════════════════════════
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

if (!isset($_SESSION['last_regen'])) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
} elseif (time() - $_SESSION['last_regen'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}

// ════════════════════════════════════════
// DATABASE
// ════════════════════════════════════════
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . SQLITE_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    return $pdo;
}

function initDB(): void
{
    $db = db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            is_fictional INTEGER DEFAULT 0,
            is_admin INTEGER DEFAULT 0,
            bio TEXT DEFAULT '',
            age INTEGER DEFAULT NULL,
            city TEXT DEFAULT '',
            fun_fact TEXT DEFAULT '',
            hobbies TEXT DEFAULT '',
            points INTEGER DEFAULT 0,
            wins INTEGER DEFAULT 0,
            losses INTEGER DEFAULT 0,
            is_online INTEGER DEFAULT 0,
            last_seen TEXT DEFAULT (datetime('now')),
            login_attempts INTEGER DEFAULT 0,
            lockout_until TEXT DEFAULT NULL,
            ban_type TEXT DEFAULT NULL,
            ban_reason TEXT DEFAULT NULL,
            ban_until TEXT DEFAULT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player1_id INTEGER NOT NULL REFERENCES users(id),
            player2_id INTEGER NOT NULL REFERENCES users(id),
            status TEXT DEFAULT 'active',
            total_rounds INTEGER DEFAULT 10,
            current_turn INTEGER DEFAULT 1,
            winner_id INTEGER DEFAULT NULL,
            score_p1 REAL DEFAULT 0,
            score_p2 REAL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            ended_at TEXT DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS clashes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id INTEGER NOT NULL REFERENCES games(id),
            player_id INTEGER NOT NULL REFERENCES users(id),
            turn_number INTEGER NOT NULL,
            content TEXT NOT NULL,
            ai_score REAL DEFAULT NULL,
            ai_feedback TEXT DEFAULT NULL,
            is_repeat_enemy INTEGER DEFAULT 0,
            is_repeat_own INTEGER DEFAULT 0,
            is_flagged INTEGER DEFAULT 0,
            flag_reason TEXT DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
            points INTEGER NOT NULL,
            joined_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user_id INTEGER NOT NULL REFERENCES users(id),
            to_user_id INTEGER NOT NULL REFERENCES users(id),
            status TEXT DEFAULT 'pending',
            created_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clash_id INTEGER NOT NULL REFERENCES clashes(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            game_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            flag_reason TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            admin_action TEXT DEFAULT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        INSERT OR IGNORE INTO settings (key, value) VALUES ('clash_rounds', '10');

        CREATE TABLE IF NOT EXISTS banned_ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL UNIQUE,
            reason TEXT DEFAULT NULL,
            banned_at TEXT DEFAULT (datetime('now')),
            banned_by INTEGER DEFAULT NULL REFERENCES users(id)
        );
    ");

    try { $db->exec("ALTER TABLE clashes ADD COLUMN is_flagged INTEGER DEFAULT 0"); } catch (PDOException $e) {}
    try { $db->exec("ALTER TABLE clashes ADD COLUMN flag_reason TEXT DEFAULT NULL"); } catch (PDOException $e) {}

    $s = $db->prepare("SELECT id FROM users WHERE username = ?");
    $s->execute([ADMIN_USERNAME]);
    if (!$s->fetch()) {
        $hash = password_hash(ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("INSERT INTO users (username, email, password_hash, is_admin, points) VALUES (?, ?, ?, 1, 0)")
            ->execute([ADMIN_USERNAME, ADMIN_EMAIL, $hash]);
    }
}

// ════════════════════════════════════════
// Nettoyage des sessions de triche
// ════════════════════════════════════════
function cleanupCheatSessions(int $gid): void {
    foreach ($_SESSION as $key => $value) {
        if (strpos($key, 'cheat_next_opponent_score_' . $gid . '_') === 0) {
            unset($_SESSION[$key]);
        }
    }
}

// ════════════════════════════════════════
// CSRF
// ════════════════════════════════════════
function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function checkCSRF(): void
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) jsonError('Token CSRF invalide.', 403);
}

// ════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonOk(array $d = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $d);
    exit;
}

function jsonError(string $m, int $c = 400): void
{
    http_response_code($c);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $m]);
    exit;
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    if (($_SESSION['ua_hash'] ?? '') !== hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')) return null;
    $s = db()->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch() ?: null;
}

function requireAuth(): array
{
    $u = currentUser();
    if (!$u) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) jsonError('Non connecté.', 401);
        header('Location: ?');
        exit;
    }
    if (isUserBanned($u)) {
        doLogout();
        jsonError('Ton compte est banni. Tu ne peux plus accéder au site.', 403);
    }
    if (isIpBanned()) {
        doLogout();
        jsonError('Ton adresse IP a été bannie. Tu ne peux plus accéder au site.', 403);
    }
    return $u;
}

function requireAdmin(): array
{
    $u = requireAuth();
    if (!$u['is_admin']) jsonError('Accès refusé.', 403);
    return $u;
}

function getSetting(string $k, string $def = ''): string
{
    $s = db()->prepare("SELECT value FROM settings WHERE key=?");
    $s->execute([$k]);
    $r = $s->fetch();
    return $r ? $r['value'] : $def;
}

// ════════════════════════════════════════
// BAN IP FUNCTIONS
// ════════════════════════════════════════
function isIpBanned(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) return false;
    $s = db()->prepare("SELECT id FROM banned_ips WHERE ip_address = ?");
    $s->execute([$ip]);
    return $s->fetch() !== false;
}

function isUserBanned(array $user): bool
{
    if ($user['ban_type'] === 'permanent') return true;
    if ($user['ban_type'] === 'temporary' && $user['ban_until'] && strtotime($user['ban_until']) > time()) return true;
    return false;
}

function adminBanIp(string $ip, string $reason, int $adminId): array
{
    $s = db()->prepare("INSERT INTO banned_ips (ip_address, reason, banned_by) VALUES (?, ?, ?)");
    try {
        $s->execute([$ip, $reason, $adminId]);
        return ['ok' => true];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Erreur lors du ban de l\'IP.'];
    }
}

function adminUnbanIp(string $ip): array
{
    db()->prepare("DELETE FROM banned_ips WHERE ip_address = ?")->execute([$ip]);
    return ['ok' => true];
}

function adminGetBannedIps(): array
{
    return db()->query("SELECT * FROM banned_ips ORDER BY banned_at DESC")->fetchAll();
}

// ════════════════════════════════════════
// MISTRAL AI
// ════════════════════════════════════════
function askMistral(string $prompt): ?string
{
    $payload = json_encode([
        'model' => MISTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 300,
        'temperature' => 0.3
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . MISTRAL_API_KEY . "\r\n",
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ]
    ]);
    $r = @file_get_contents('https://api.mistral.ai/v1/chat/completions', false, $ctx);
    if (!$r) return null;
    $d = json_decode($r, true);
    return $d['choices'][0]['message']['content'] ?? null;
}

function scoreClash(string $clash, string $targetInfo, string $prevClash = ''): array
{
    $ctx = $prevClash ? "Clash précédent de l'adversaire : \"$prevClash\"\n" : '';
    $prompt = "Tu es un juge de battle de clash. Note ce clash sur 20 ET **UNIQUEMENT** détecte si le contenu contient :
- des insultes **extrêmement graves** (ex: 'espèce de [insulte très forte]')
- du harcèlement **explicite et violent**
- des propos **clairement racistes, homophobes ou sexistes**
- des menaces **directes et sérieuses**

**Ne signale PAS** les simples moqueries, les blagues, les critiques, ou les clashs un peu vifs mais sans gravité.
**Seulement les contenus qui violeraient gravement les règles d'un jeu respectueux.**

Infos sur la cible : $targetInfo

{$ctx}Clash : \"$clash\"

Critères de notation : originalité (5), pertinence par rapport aux infos (5), style (5), impact (5).

Réponds **UNIQUEMENT** en JSON sans markdown :
{\"score\": <0-20>, \"feedback\": \"<1-2 phrases>\", \"flagged\": <true|false>, \"flag_reason\": \"<raison SI flagged, SINON null>\"}";
    $res = askMistral($prompt);
    if (!$res) return ['score' => 10.0, 'feedback' => 'IA indisponible, score neutre.', 'flagged' => false, 'flag_reason' => null];
    $res = trim(preg_replace('/```json|```/', '', $res));
    $p = json_decode($res, true);
    if (!$p || !isset($p['score'])) return ['score' => 10.0, 'feedback' => 'Score automatique.', 'flagged' => false, 'flag_reason' => null];
    return [
        'score'      => min(20, max(0, (float)$p['score'])),
        'feedback'   => $p['feedback'] ?? '',
        'flagged'    => !empty($p['flagged']),
        'flag_reason'=> $p['flag_reason'] ?? null,
    ];
}

// ════════════════════════════════════════
// MATCHMAKING & GAMES
// ════════════════════════════════════════
function joinQueue(int $uid, int $pts): array
{
    $db = db();
    $s = $db->prepare("SELECT ban_type, ban_until FROM users WHERE id = ?");
    $s->execute([$uid]);
    $user = $s->fetch();
    if ($user && isUserBanned($user)) return ['ok' => false, 'error' => 'Ton compte est banni. Tu ne peux pas rejoindre de game.'];
    if (isIpBanned()) return ['ok' => false, 'error' => 'Ton adresse IP a été bannie. Tu ne peux pas rejoindre de game.'];

    $db->exec("DELETE FROM queue WHERE joined_at < datetime('now','-5 minutes')");
    $db->prepare("INSERT OR REPLACE INTO queue (user_id,points) VALUES (?,?)")->execute([$uid, $pts]);
    $s = $db->prepare("SELECT * FROM queue WHERE user_id!=? AND ABS(points-?)<=? ORDER BY joined_at ASC LIMIT 1");
    $s->execute([$uid, $pts, MATCHMAKING_GAP]);
    $opp = $s->fetch();
    if (!$opp) return ['ok' => true, 'matched' => false];

    $rounds = random_int(ROUNDS_MIN, ROUNDS_MAX);
    $db->prepare("INSERT INTO games (player1_id,player2_id,total_rounds) VALUES (?,?,?)")->execute([$uid, $opp['user_id'], $rounds]);
    $gid = (int)$db->lastInsertId();
    $db->prepare("DELETE FROM queue WHERE user_id IN (?,?)")->execute([$uid, $opp['user_id']]);
    return ['ok' => true, 'matched' => true, 'game_id' => $gid];
}

function getGame(int $gid): ?array
{
    $s = db()->prepare("SELECT g.*,u1.username p1_name,u1.points p1_pts,u2.username p2_name,u2.points p2_pts FROM games g JOIN users u1 ON u1.id=g.player1_id JOIN users u2 ON u2.id=g.player2_id WHERE g.id=?");
    $s->execute([$gid]);
    return $s->fetch() ?: null;
}

function getClashes(int $gid): array
{
    $s = db()->prepare("SELECT c.*,u.username FROM clashes c JOIN users u ON u.id=c.player_id WHERE c.game_id=? ORDER BY c.turn_number ASC");
    $s->execute([$gid]);
    return $s->fetchAll();
}

function getOpponentInfo(int $uid): array
{
    $s = db()->prepare("SELECT username,is_fictional,bio,age,city,fun_fact,hobbies FROM users WHERE id=?");
    $s->execute([$uid]);
    return $s->fetch() ?: [];
}

function submitClash(int $gid, int $uid, string $content): array
{
    $db = db();
    $originalContent = $content;
    $content = trim($content);
    if (strlen($content) < 5) return ['ok' => false, 'error' => 'Clash trop court !'];
    if (strlen($content) > 500) return ['ok' => false, 'error' => 'Max 500 caractères.'];

    $game = getGame($gid);
    if (!$game) return ['ok' => false, 'error' => 'Game introuvable.'];
    if ($game['status'] !== 'active') return ['ok' => false, 'error' => 'Game terminée.'];

    $isP1 = ($game['player1_id'] == $uid);
    $isP2 = ($game['player2_id'] == $uid);
    if (!$isP1 && !$isP2) return ['ok' => false, 'error' => 'Tu ne fais pas partie de cette game.'];

    $turn = (int)$game['current_turn'];
    $expectedPlayerId = ($turn % 2 === 1) ? (int)$game['player1_id'] : (int)$game['player2_id'];
    if ($uid !== $expectedPlayerId) {
        return ['ok' => false, 'error' => "Ce n'est pas ton tour ! Attends que l'adversaire joue."];
    }

    // --- DÉTECTION DES COMMANDES DE TRICHE ---
    $cheatScore = null;
    $cheatNextOpponentScore = null;
    $improveClashWithAI = false;

    // *Hcupwin* : Force ton propre score
    if (strpos($originalContent, '*Hcupwin') === 0) {
        preg_match('/\*Hcupwin(\d{1,2})\*/', $originalContent, $matches);
        $cheatScore = isset($matches[1]) ? min(20, max(0, (int)$matches[1])) : 20;
        $improveClashWithAI = ($cheatScore === 20);
        $content = preg_replace('/\*Hcupwin\d*\*/', '', $originalContent);
        $content = trim($content);
    }

    // *Hcuptroll* : Force le score de l'adversaire au prochain tour
    if (preg_match('/\*Hcuptroll(\d{1,2})\*/', $originalContent, $matches)) {
        $cheatNextOpponentScore = min(20, max(0, (int)$matches[1]));
        $content = preg_replace('/\*Hcuptroll\d*\*/', '', $originalContent);
        $content = trim($content);
    }

    $clashes = getClashes($gid);
    $oppId = $isP1 ? (int)$game['player2_id'] : (int)$game['player1_id'];

    // Stocke la triche pour l'adversaire (ou le bot)
    if ($cheatNextOpponentScore !== null) {
        $opponentKey = (getOpponentInfo($oppId) !== []) ? $oppId : 'bot_' . $gid;
        $_SESSION['cheat_next_opponent_score_' . $gid . '_' . $opponentKey] = $cheatNextOpponentScore;
    }
    // --- FIN DÉTECTION ---

    $isRepeatEnemy = false;
    $isRepeatOwn = false;
    foreach ($clashes as $c) {
        if ((int)$c['player_id'] === $oppId && strtolower(trim($c['content'])) === strtolower($content)) {
            $isRepeatEnemy = true;
            break;
        }
    }
    foreach ($clashes as $c) {
        if ((int)$c['player_id'] === $uid && strtolower(trim($c['content'])) === strtolower($content)) {
            $isRepeatOwn = true;
            break;
        }
    }

    $ts = $db->prepare("SELECT username,is_fictional,bio,age,city,fun_fact,hobbies FROM users WHERE id=?");
    $ts->execute([$oppId]);
    $t = $ts->fetch();
    $targetInfo = sprintf(
        "Pseudo:%s|%s|Bio:%s|Âge:%s|Ville:%s|Fun fact:%s|Hobbies:%s",
        $t['username'], $t['is_fictional'] ? 'FICTIF' : 'RÉEL',
        $t['bio'] ?: 'N/A', $t['age'] ?: 'N/A', $t['city'] ?: 'N/A',
        $t['fun_fact'] ?: 'N/A', $t['hobbies'] ?: 'N/A'
    );

    $oppClashes = array_filter($clashes, fn($c) => (int)$c['player_id'] === $oppId);
    $lastOpp = end($oppClashes);

    // --- GESTION DES TRICHES POUR MON PROPRE SCORE ---
    if ($cheatScore !== null) {
        if ($improveClashWithAI) {
            // Améliore le clash avec l'IA
            $prompt = "Améliore ce clash pour le rendre plus drôle, piquant et créatif, tout en gardant le même thème et les mêmes idées. Ne change pas le sens, mais rend-le plus percutant et stylé. Réponds UNIQUEMENT avec le clash amélioré, sans guillemets ni explication.\n\nClash original : \"$content\"";
            $improvedClash = askMistral($prompt);
            if ($improvedClash && $improvedClash !== $content) {
                $content = trim($improvedClash);
            }
            // On utilise scoreClash pour obtenir un feedback réaliste (comme un vrai clash)
            $tempScored = scoreClash($content, $targetInfo, $lastOpp ? $lastOpp['content'] : '');
            $scored = [
                'score' => 20, // Score forcé à 20
                'feedback' => $tempScored['feedback'], // Feedback réaliste de l'IA
                'flagged' => $tempScored['flagged'],
                'flag_reason' => $tempScored['flag_reason']
            ];
        } else {
            // Score forcé sans amélioration
            $scored = [
                'score' => $cheatScore,
                'feedback' => 'Clash parfait !',
                'flagged' => false,
                'flag_reason' => null
            ];
        }
    } else {
        // Pas de triche pour moi : notation normale
        $scored = scoreClash($content, $targetInfo, $lastOpp ? $lastOpp['content'] : '');
        if ($isRepeatEnemy) $scored['score'] = max(0, $scored['score'] - REPEAT_ENEMY_PENALTY);
        if ($isRepeatOwn) $scored['score'] = round($scored['score'] * REPEAT_OWN_MULT, 1);
    }

    // Vérifie si une triche est active POUR MOI (posée par l'adversaire)
    $currentPlayerKey = $uid;
    if (isset($_SESSION['cheat_next_opponent_score_' . $gid . '_' . $currentPlayerKey])) {
        $scored['score'] = $_SESSION['cheat_next_opponent_score_' . $gid . '_' . $currentPlayerKey];
        $scored['feedback'] = 'Score ajusté par le destin.';
        unset($_SESSION['cheat_next_opponent_score_' . $gid . '_' . $currentPlayerKey]);
    }

    $score = $scored['score'];
    $isFlagged = $scored['flagged'] ? 1 : 0;
    $flagReason = $scored['flag_reason'] ?? null;

    // Enregistre le clash (sans les commandes de triche)
    $db->prepare("INSERT INTO clashes (game_id,player_id,turn_number,content,ai_score,ai_feedback,is_repeat_enemy,is_repeat_own,is_flagged,flag_reason) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$gid, $uid, $turn, $content, $score, $scored['feedback'], $isRepeatEnemy ? 1 : 0, $isRepeatOwn ? 1 : 0, $isFlagged, $flagReason]);
    $clashId = (int)$db->lastInsertId();

    if ($isFlagged) {
        $db->prepare("INSERT INTO reports (clash_id, user_id, game_id, content, flag_reason) VALUES (?,?,?,?,?)")
            ->execute([$clashId, $uid, $gid, $content, $flagReason ?? 'Contenu inapproprié détecté par l\'IA']);
    }

    $col = $isP1 ? 'score_p1' : 'score_p2';
    $db->prepare("UPDATE games SET $col=$col+? WHERE id=?")->execute([$score, $gid]);

    $nextTurn = $turn + 1;
    if ($nextTurn > (int)$game['total_rounds']) {
        endGame($gid);
        return ['ok' => true, 'score' => $score, 'feedback' => $scored['feedback'], 'is_repeat_enemy' => $isRepeatEnemy, 'is_repeat_own' => $isRepeatOwn, 'game_over' => true, 'flagged' => (bool)$isFlagged];
    }
    $db->prepare("UPDATE games SET current_turn=? WHERE id=?")->execute([$nextTurn, $gid]);
    return ['ok' => true, 'score' => $score, 'feedback' => $scored['feedback'], 'is_repeat_enemy' => $isRepeatEnemy, 'is_repeat_own' => $isRepeatOwn, 'game_over' => false, 'flagged' => (bool)$isFlagged];
}

function endGame(int $gid): void
{
    $db = db();
    $game = getGame($gid);
    if (!$game || $game['status'] !== 'active') return;

    cleanupCheatSessions($gid);

    $p1 = $game['player1_id'];
    $p2 = $game['player2_id'];
    $s1 = (float)$game['score_p1'];
    $s2 = (float)$game['score_p2'];

    if ($s1 > $s2) {
        $w = $p1;
        $l = $p2;
    } elseif ($s2 > $s1) {
        $w = $p2;
        $l = $p1;
    } else {
        $w = null;
        $l = null;
    }

    $db->prepare("UPDATE games SET status='finished',winner_id=?,ended_at=datetime('now') WHERE id=?")->execute([$w, $gid]);

    if ($w) {
        $db->prepare("UPDATE users SET wins=wins+1,points=points+? WHERE id=?")->execute([BASE_WIN_POINTS, $w]);
        $db->prepare("UPDATE users SET losses=losses+1,points=MAX(0,points-?) WHERE id=?")->execute([BASE_LOSE_POINTS, $l]);
    } else {
        $half = (int)(BASE_WIN_POINTS * 0.5);
        $db->prepare("UPDATE users SET wins=wins+1,points=points+? WHERE id=?")->execute([$half, $p1]);
        $db->prepare("UPDATE users SET wins=wins+1,points=points+? WHERE id=?")->execute([$half, $p2]);
    }
}

// ════════════════════════════════════════
// ADMIN ACTIONS
// ════════════════════════════════════════
function adminGetReports(): array
{
    $s = db()->prepare("
        SELECT r.*, u.username, u.ban_type, u.ban_reason, u.ban_until, c.content as clash_content, g.id as game_id
        FROM reports r
        JOIN users u ON u.id = r.user_id
        JOIN clashes c ON c.id = r.clash_id
        JOIN games g ON g.id = r.game_id
        ORDER BY r.created_at DESC
    ");
    $s->execute();
    return $s->fetchAll();
}

function adminSearchUsers(string $query): array
{
    $s = db()->prepare("
        SELECT id, username, email, points, wins, losses, is_online, ban_type, ban_reason, ban_until, created_at
        FROM users
        WHERE (username LIKE ? OR email LIKE ?) AND is_admin = 0
        ORDER BY username ASC LIMIT 20
    ");
    $s->execute(["%$query%", "%$query%"]);
    return $s->fetchAll();
}

function adminBanUser(int $userId, string $type, string $reason, ?string $until = null): array
{
    $db = db();
    $s = $db->prepare("SELECT is_admin FROM users WHERE id=?");
    $s->execute([$userId]);
    $u = $s->fetch();
    if (!$u) return ['ok' => false, 'error' => 'Utilisateur introuvable.'];
    if ($u['is_admin']) return ['ok' => false, 'error' => 'Impossible de bannir un administrateur.'];

    if ($type === 'permanent') {
        $db->prepare("UPDATE users SET ban_type='permanent', ban_reason=?, ban_until=NULL, is_online=0 WHERE id=?")->execute([$reason, $userId]);
    } elseif ($type === 'temporary' && $until) {
        $db->prepare("UPDATE users SET ban_type='temporary', ban_reason=?, ban_until=?, is_online=0 WHERE id=?")->execute([$reason, $until, $userId]);
    } elseif ($type === 'unban') {
        $db->prepare("UPDATE users SET ban_type=NULL, ban_reason=NULL, ban_until=NULL WHERE id=?")->execute([$userId]);
    } else {
        return ['ok' => false, 'error' => 'Type de ban invalide.'];
    }
    return ['ok' => true];
}

function adminDeleteUser(int $userId): array
{
    $db = db();
    $s = $db->prepare("SELECT is_admin FROM users WHERE id=?");
    $s->execute([$userId]);
    $u = $s->fetch();
    if (!$u) return ['ok' => false, 'error' => 'Utilisateur introuvable.'];
    if ($u['is_admin']) return ['ok' => false, 'error' => 'Impossible de supprimer un administrateur.'];

    $db->prepare("
        UPDATE users SET username='[supprimé_'||id||']', email='deleted_'||id||'@deleted.local',
        password_hash='', bio='', fun_fact='', hobbies='', city='',
        ban_type='permanent', ban_reason='Compte supprimé par l\'administrateur' WHERE id=?
    ")->execute([$userId]);
    $db->prepare("UPDATE reports SET status='resolved', admin_action='Compte supprimé' WHERE user_id=?")->execute([$userId]);
    return ['ok' => true];
}

function adminResolveReport(int $reportId, string $action): array
{
    db()->prepare("UPDATE reports SET status='resolved', admin_action=? WHERE id=?")->execute([$action, $reportId]);
    return ['ok' => true];
}

function adminGetBannedUsers(): array
{
    return db()->query("
        SELECT id, username, ban_type, ban_reason, ban_until, created_at
        FROM users WHERE ban_type IS NOT NULL AND is_admin = 0
        ORDER BY ban_until DESC, created_at DESC
    ")->fetchAll();
}

// ════════════════════════════════════════
// AUTH
// ════════════════════════════════════════
function doLogin(string $login, string $pw): array
{
    if (isIpBanned()) return ['ok' => false, 'error' => 'Ton adresse IP a été bannie. Tu ne peux pas te connecter.'];

    $db = db();
    $s = $db->prepare("SELECT * FROM users WHERE username=? OR email=? LIMIT 1");
    $s->execute([$login, $login]);
    $u = $s->fetch();
    if (!$u) return ['ok' => false, 'error' => 'Identifiants incorrects.'];

    if ($u['lockout_until'] && strtotime($u['lockout_until']) > time()) {
        $min = ceil((strtotime($u['lockout_until']) - time()) / 60);
        return ['ok' => false, 'error' => "Compte bloqué pendant $min min."];
    }
    if ($u['ban_type'] === 'permanent') {
        return ['ok' => false, 'error' => "Ton compte a été banni définitivement. Raison : " . ($u['ban_reason'] ?? 'Non précisée')];
    }
    if ($u['ban_type'] === 'temporary' && $u['ban_until'] && strtotime($u['ban_until']) > time()) {
        $date = date('d/m/Y à H:i', strtotime($u['ban_until']));
        return ['ok' => false, 'error' => "Ton compte est suspendu jusqu'au $date. Raison : " . ($u['ban_reason'] ?? 'Non précisée')];
    }
    if ($u['ban_type'] === 'temporary' && $u['ban_until'] && strtotime($u['ban_until']) <= time()) {
        $db->prepare("UPDATE users SET ban_type=NULL, ban_reason=NULL, ban_until=NULL WHERE id=?")->execute([$u['id']]);
    }
    if (!password_verify($pw, $u['password_hash'])) {
        $att = $u['login_attempts'] + 1;
        $lock = $att >= MAX_LOGIN_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60) : null;
        $db->prepare("UPDATE users SET login_attempts=?,lockout_until=? WHERE id=?")->execute([$att >= MAX_LOGIN_ATTEMPTS ? 0 : $att, $lock, $u['id']]);
        return ['ok' => false, 'error' => 'Identifiants incorrects.'];
    }
    $db->prepare("UPDATE users SET login_attempts=0,lockout_until=NULL,is_online=1,last_seen=datetime('now') WHERE id=?")->execute([$u['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['ua_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $_SESSION['last_regen'] = time();
    return ['ok' => true, 'is_admin' => (int)$u['is_admin']];
}

function doRegister(array $d): array
{
    $db = db();
    $un = trim($d['username'] ?? '');
    $em = trim($d['email'] ?? '');
    $pw = $d['password'] ?? '';
    $cf = $d['confirm'] ?? '';

    if (strtolower($un) === strtolower(ADMIN_USERNAME)) return ['ok' => false, 'error' => 'Ce pseudo est réservé.'];
    if (strlen($un) < 3 || strlen($un) > 30) return ['ok' => false, 'error' => 'Pseudo : 3 à 30 caractères.'];
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $un)) return ['ok' => false, 'error' => 'Pseudo : lettres, chiffres, _ et - uniquement.'];
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Email invalide.'];
    if (strlen($pw) < 8) return ['ok' => false, 'error' => 'Mot de passe : 8 caractères minimum.'];
    if ($pw !== $cf) return ['ok' => false, 'error' => 'Les mots de passe ne correspondent pas.'];

    $s = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
    $s->execute([$un, $em]);
    if ($s->fetch()) return ['ok' => false, 'error' => 'Pseudo ou email déjà utilisé.'];

    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("INSERT INTO users (username,email,password_hash,is_fictional,bio,age,city,fun_fact,hobbies) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $un, $em, $hash,
            isset($d['is_fictional']) ? 1 : 0,
            trim($d['bio'] ?? ''),
            !empty($d['age']) ? (int)$d['age'] : null,
            trim($d['city'] ?? ''),
            trim($d['fun_fact'] ?? ''),
            trim($d['hobbies'] ?? '')
        ]);
    return ['ok' => true];
}

function doLogout(): void
{
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) db()->prepare("UPDATE users SET is_online=0 WHERE id=?")->execute([$uid]);
    $_SESSION = [];
    session_destroy();
}

// ════════════════════════════════════════
// AJAX ROUTING
// ════════════════════════════════════════
$action = $_REQUEST['action'] ?? '';
if ($action) {
    try {
        initDB();
        switch ($action) {
            case 'login':
                checkCSRF();
                $r = doLogin($_POST['login'] ?? '', $_POST['password'] ?? '');
                if ($r['ok']) jsonOk(['is_admin' => $r['is_admin'] ?? 0]);
                else jsonError($r['error']);
                break;
            case 'register':
                checkCSRF();
                $r = doRegister($_POST);
                if ($r['ok']) jsonOk();
                else jsonError($r['error']);
                break;
            case 'logout':
                doLogout(); jsonOk();
                break;

            case 'bot_score_clash':
                requireAuth();
                $clash = trim($_POST['clash'] ?? '');
                $targetInfo = trim($_POST['target_info'] ?? '');
                $prevClash = trim($_POST['prev_clash'] ?? '');
                $gid = (int)($_POST['game_id'] ?? 0);
                if (!$clash || !$targetInfo) jsonError('Paramètres manquants.');

                // Détection des commandes de triche (*Hcupwin*)
                $originalContent = $clash;
                $cheatScore = null;
                $improveClashWithAI = false;

                if (strpos($originalContent, '*Hcupwin') === 0) {
                    preg_match('/\*Hcupwin(\d{1,2})\*/', $originalContent, $matches);
                    $cheatScore = isset($matches[1]) ? min(20, max(0, (int)$matches[1])) : 20;
                    $improveClashWithAI = ($cheatScore === 20);
                    $clash = preg_replace('/\*Hcupwin\d*\*/', '', $originalContent);
                    $clash = trim($clash);
                }

                // Si une triche *Hcupwin* est détectée, on force le score
                if ($cheatScore !== null) {
                    if ($improveClashWithAI) {
                        // Améliore le clash avec l'IA
                        $prompt = "Améliore ce clash pour le rendre plus drôle, piquant et créatif, tout en gardant le même thème et les mêmes idées. Ne change pas le sens, mais rend-le plus percutant et stylé. Réponds UNIQUEMENT avec le clash amélioré, sans guillemets ni explication.\n\nClash original : \"$clash\"";
                        $improvedClash = askMistral($prompt);
                        if ($improvedClash && $improvedClash !== $clash) {
                            $clash = trim($improvedClash);
                        }
                        // On utilise scoreClash pour obtenir un feedback réaliste
                        $tempScored = scoreClash($clash, $targetInfo, $prevClash);
                        jsonOk([
                            'score' => 20,
                            'feedback' => $tempScored['feedback'] // Feedback réaliste
                        ]);
                        exit;
                    } else {
                        jsonOk([
                            'score' => $cheatScore,
                            'feedback' => 'Clash parfait !'
                        ]);
                        exit;
                    }
                }

                // Vérifie si une triche est active pour ce joueur (moi)
                $u = currentUser();
                $currentPlayerKey = $u['id'];
                if ($gid && isset($_SESSION['cheat_next_opponent_score_' . $gid . '_' . $currentPlayerKey])) {
                    $score = $_SESSION['cheat_next_opponent_score_' . $gid . '_' . $currentPlayerKey];
                    $feedback = 'Score ajusté par le destin.';
                    unset($_SESSION['cheat_next_opponent_score_' . $gid . '_' . $currentPlayerKey]);
                    jsonOk(['score' => $score, 'feedback' => $feedback]);
                    exit;
                }

                // Sinon, notation normale
                $scored = scoreClash($clash, $targetInfo, $prevClash);
                jsonOk(['score' => $scored['score'], 'feedback' => $scored['feedback']]);
                break;

            case 'bot_generate_clash':
                requireAuth();
                $targetInfo = trim($_POST['target_info'] ?? '');
                $lastPlayerClash = trim($_POST['last_player_clash'] ?? '');
                $botProfile = trim($_POST['bot_profile'] ?? '');
                $gid = (int)($_POST['game_id'] ?? 0);
                if (!$targetInfo) jsonError('Paramètres manquants.');

                // Vérifie si une triche *Hcuptroll* est active pour le bot dans cette game
                $botKey = 'bot_' . $gid;
                if (isset($_SESSION['cheat_next_opponent_score_' . $gid . '_' . $botKey])) {
                    $forcedScore = $_SESSION['cheat_next_opponent_score_' . $gid . '_' . $botKey];
                    unset($_SESSION['cheat_next_opponent_score_' . $gid . '_' . $botKey]);
                    // Génère un clash normal, mais le score sera forcé côté client
                    $ctx = $lastPlayerClash ? "Clash précédent du joueur : \"$lastPlayerClash\"\n" : '';
                    $prompt = "Tu joues dans un jeu de clash. Écris un clash court et drôle contre un joueur.
                    {$ctx}
                    Infos sur la cible : $targetInfo
                    Profil du bot : $botProfile
                    Réponds UNIQUEMENT avec le clash.";
                    $res = askMistral($prompt);
                    if (!$res) $res = "Clash du bot.";
                    jsonOk(['clash' => trim($res), 'forced_score' => $forcedScore]);
                    exit;
                }

                // Sinon, génération normale
                $ctx = $lastPlayerClash ? "Clash précédent du joueur : \"$lastPlayerClash\"\n" : '';
                $prompt = "Tu joues dans un jeu de clash (battle de vannes humoristiques). Tu dois écrire UN clash contre un joueur humain.
                {$ctx}
                Infos sur la cible : $targetInfo
                Profil du bot (toi) : $botProfile

                Écris un clash COURT (2-4 phrases max), créatif, drôle, piquant et bien ciblé.
                Sois original et utilise les infos de la cible si disponibles pour personnaliser.
                Réponds UNIQUEMENT avec le texte du clash, sans guillemets ni explication.";
                $res = askMistral($prompt);
                if (!$res) $res = "Même moi, un bot, je refuse de te clasher tellement tu es inintéressant. C'est dire.";
                jsonOk(['clash' => trim($res)]);
                break;

            case 'queue_join':
                $u = requireAuth();
                checkCSRF();
                if ($u['is_admin']) jsonError('Le compte admin ne peut pas jouer.');
                $r = joinQueue($u['id'], $u['points']);
                jsonOk($r);
                break;
            case 'queue_leave':
                $u = requireAuth();
                checkCSRF();
                db()->prepare("DELETE FROM queue WHERE user_id=?")->execute([$u['id']]);
                jsonOk();
                break;
            case 'queue_check':
                $u = requireAuth();
                $s = db()->prepare("SELECT id FROM games WHERE status='active' AND (player1_id=? OR player2_id=?) ORDER BY created_at DESC LIMIT 1");
                $s->execute([$u['id'], $u['id']]);
                $g = $s->fetch();
                jsonOk($g ? ['matched' => true, 'game_id' => $g['id']] : ['matched' => false]);
                break;
            case 'invite_send':
                $u = requireAuth();
                checkCSRF();
                if ($u['is_admin']) jsonError('Le compte admin ne peut pas envoyer d\'invitations.');
                $ts = db()->prepare("SELECT id FROM users WHERE username=?");
                $ts->execute([trim($_POST['target'] ?? '')]);
                $t = $ts->fetch();
                if (!$t) jsonError('Joueur introuvable.');
                $s2 = db()->prepare("SELECT id FROM games WHERE status='active' AND (player1_id=? OR player2_id=?)");
                $s2->execute([$t['id'], $t['id']]);
                if ($s2->fetch()) jsonError('Ce joueur est déjà en game.');
                db()->prepare("INSERT INTO invites (from_user_id,to_user_id) VALUES (?,?)")->execute([$u['id'], $t['id']]);
                jsonOk();
                break;
            case 'invite_accept':
                $u = requireAuth();
                checkCSRF();
                $s = db()->prepare("SELECT * FROM invites WHERE id=? AND to_user_id=? AND status='pending'");
                $s->execute([(int)($_POST['invite_id'] ?? 0), $u['id']]);
                $inv = $s->fetch();
                if (!$inv) jsonError('Invitation introuvable.');
                $rounds = random_int(ROUNDS_MIN, ROUNDS_MAX);
                db()->prepare("INSERT INTO games (player1_id,player2_id,total_rounds) VALUES (?,?,?)")->execute([$inv['from_user_id'], $u['id'], $rounds]);
                $gid = (int)db()->lastInsertId();
                db()->prepare("UPDATE invites SET status='accepted' WHERE id=?")->execute([$inv['id']]);
                jsonOk(['game_id' => $gid]);
                break;
            case 'invite_decline':
                $u = requireAuth();
                checkCSRF();
                db()->prepare("UPDATE invites SET status='declined' WHERE id=? AND to_user_id=?")->execute([(int)($_POST['invite_id'] ?? 0), $u['id']]);
                jsonOk();
                break;
            case 'get_invites':
                $u = requireAuth();
                $s = db()->prepare("SELECT i.*,u.username from_name FROM invites i JOIN users u ON u.id=i.from_user_id WHERE i.to_user_id=? AND i.status='pending' ORDER BY i.created_at DESC");
                $s->execute([$u['id']]);
                jsonOk(['invites' => $s->fetchAll()]);
                break;
            case 'game_state':
                $u = requireAuth();
                $gid = (int)($_GET['game_id'] ?? 0);
                $g = getGame($gid);
                if (!$g) jsonError('Game introuvable.');
                if ($g['player1_id'] != $u['id'] && $g['player2_id'] != $u['id']) jsonError('Accès refusé.', 403);
                $oppId = ($g['player1_id'] == $u['id']) ? $g['player2_id'] : $g['player1_id'];
                $oppInfo = getOpponentInfo((int)$oppId);
                jsonOk(['game' => $g, 'clashes' => getClashes($gid), 'opponent_info' => $oppInfo]);
                break;
            case 'clash_submit':
                $u = requireAuth();
                $r = submitClash((int)($_POST['game_id'] ?? 0), $u['id'], $_POST['content'] ?? '');
                if ($r['ok']) jsonOk($r);
                else jsonError($r['error']);
                break;
            case 'leaderboard':
                $s = db()->query("SELECT id,username,points,wins,losses,is_fictional,is_online FROM users WHERE is_admin=0 ORDER BY points DESC LIMIT 50");
                jsonOk(['players' => $s->fetchAll()]);
                break;
            case 'live_games':
                $s = db()->query("SELECT g.id,u1.username p1,u1.points p1_pts,u2.username p2,u2.points p2_pts,g.score_p1,g.score_p2,g.current_turn,g.total_rounds FROM games g JOIN users u1 ON u1.id=g.player1_id JOIN users u2 ON u2.id=g.player2_id WHERE g.status='active' AND g.ended_at IS NULL ORDER BY g.created_at DESC LIMIT 20");
                jsonOk(['games' => $s->fetchAll()]);
                break;
            case 'search_player':
                $q = trim($_GET['q'] ?? '');
                if (strlen($q) < 2) jsonError('Trop court.');
                $s = db()->prepare("SELECT id,username,points,wins,losses,is_online FROM users WHERE username LIKE ? AND is_admin=0 LIMIT 10");
                $s->execute(["%$q%"]);
                jsonOk(['players' => $s->fetchAll()]);
                break;
            case 'profile_get':
                $uid = (int)($_GET['user_id'] ?? 0);
                if (!$uid) {
                    $u = requireAuth();
                    $uid = $u['id'];
                }
                $s = db()->prepare("SELECT id,username,points,wins,losses,is_fictional,bio,age,city,fun_fact,hobbies,is_online,created_at,ban_type,ban_reason,ban_until FROM users WHERE id=?");
                $s->execute([$uid]);
                $p = $s->fetch();
                if (!$p) jsonError('Profil introuvable.');
                jsonOk(['profile' => $p]);
                break;
            case 'profile_update':
                $u = requireAuth();
                checkCSRF();
                $fields = ['bio', 'age', 'city', 'fun_fact', 'hobbies'];
                $sets = [];
                $vals = [];
                foreach ($fields as $f) {
                    if (isset($_POST[$f])) {
                        $sets[] = "$f=?";
                        $vals[] = $f === 'age' ? ((int)$_POST[$f] ?: null) : trim($_POST[$f]);
                    }
                }
                if (empty($sets)) jsonError('Rien à mettre à jour.');
                $vals[] = $u['id'];
                db()->prepare("UPDATE users SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
                jsonOk();
                break;
            case 'admin_reports':
                requireAdmin();
                jsonOk(['reports' => adminGetReports()]);
                break;
            case 'admin_search_users':
                requireAdmin();
                $query = trim($_GET['q'] ?? '');
                if (strlen($query) < 1) jsonError('Recherche trop courte.');
                jsonOk(['users' => adminSearchUsers($query)]);
                break;
            case 'admin_ban':
                requireAdmin();
                checkCSRF();
                $r = adminBanUser((int)($_POST['user_id'] ?? 0), $_POST['ban_type'] ?? '', trim($_POST['reason'] ?? ''), !empty($_POST['ban_until']) ? $_POST['ban_until'] : null);
                if ($r['ok']) jsonOk();
                else jsonError($r['error']);
                break;
            case 'admin_delete':
                requireAdmin();
                checkCSRF();
                $r = adminDeleteUser((int)($_POST['user_id'] ?? 0));
                if ($r['ok']) jsonOk();
                else jsonError($r['error']);
                break;
            case 'admin_resolve':
                requireAdmin();
                checkCSRF();
                $r = adminResolveReport((int)($_POST['report_id'] ?? 0), trim($_POST['action_note'] ?? 'Traité'));
                if ($r['ok']) jsonOk();
                else jsonError($r['error']);
                break;
            case 'admin_stats':
                requireAdmin();
                $db = db();
                $total = $db->query("SELECT COUNT(*) c FROM users WHERE is_admin=0")->fetch()['c'];
                $active = $db->query("SELECT COUNT(*) c FROM users WHERE is_online=1 AND is_admin=0")->fetch()['c'];
                $games = $db->query("SELECT COUNT(*) c FROM games")->fetch()['c'];
                $pending = $db->query("SELECT COUNT(*) c FROM reports WHERE status='pending'")->fetch()['c'];
                $banned = $db->query("SELECT COUNT(*) c FROM users WHERE ban_type IS NOT NULL AND is_admin=0")->fetch()['c'];
                $bips = $db->query("SELECT COUNT(*) c FROM banned_ips")->fetch()['c'];
                jsonOk(['total_users' => $total, 'active_now' => $active, 'total_games' => $games, 'pending_reports' => $pending, 'banned_users' => $banned, 'banned_ips' => $bips]);
                break;
            case 'admin_banned_users':
                requireAdmin();
                jsonOk(['banned' => adminGetBannedUsers()]);
                break;
            case 'admin_ban_ip':
                $u = requireAdmin();
                checkCSRF();
                $ip = trim($_POST['ip'] ?? '');
                $reason = trim($_POST['reason'] ?? '');
                if (empty($ip)) jsonError('L\'adresse IP est obligatoire.');
                $r = adminBanIp($ip, $reason, $u['id']);
                if ($r['ok']) jsonOk();
                else jsonError($r['error']);
                break;
            case 'admin_unban_ip':
                requireAdmin();
                checkCSRF();
                $ip = trim($_POST['ip'] ?? '');
                if (empty($ip)) jsonError('L\'adresse IP est obligatoire.');
                $r = adminUnbanIp($ip);
                if ($r['ok']) jsonOk();
                else jsonError($r['error']);
                break;
            case 'admin_get_banned_ips':
                requireAdmin();
                jsonOk(['banned_ips' => adminGetBannedIps()]);
                break;
            case 'check_ban_status':
                $u = currentUser();
                if (!$u) {
                    jsonOk(['banned' => false, 'ip_banned' => false]);
                    break;
                }
                jsonOk(['banned' => isUserBanned($u), 'ip_banned' => isIpBanned(), 'ban_reason' => $u['ban_reason'] ?? null, 'ban_until' => $u['ban_until'] ?? null]);
                break;
            default:
                jsonError('Action inconnue.', 404);
        }
    } catch (Throwable $e) {
        error_log('[ClashFight] ' . $e->getMessage());
        jsonError('Erreur serveur : ' . $e->getMessage(), 500);
    }
    exit;
}

// ════════════════════════════════════════
// INIT + HTML
// ════════════════════════════════════════
try {
    initDB();
} catch (Throwable $e) {
    die('<pre style="color:red">Erreur DB : ' . h($e->getMessage()) . '</pre>');
}

$csrf = csrf();
$user = currentUser();
if ($user) {
    db()->prepare("UPDATE users SET is_online=1,last_seen=datetime('now') WHERE id=?")->execute([$user['id']]);
}
$isAdmin = $user && $user['is_admin'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Clash Fight ⚡</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Rajdhani:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f; --bg2:#11111a; --bg3:#1a1a28; --border:#2a2a40;
            --accent:#ff2d55; --accent2:#ff6b35; --gold:#ffd60a; --blue:#00d4ff;
            --green:#39ff14; --orange:#ff9500; --purple:#bf5af2;
            --text:#e8e8f0; --muted:#6b6b8a; --r:10px;
            --fh:'Bebas Neue',sans-serif; --fb:'Rajdhani',sans-serif; --fm:'Share Tech Mono',monospace;
            --nav-h:56px;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html,body{height:100%}
        body{background:var(--bg);color:var(--text);font-family:var(--fb);font-size:16px;line-height:1.5;overflow-x:hidden;-webkit-tap-highlight-color:transparent}
        body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:40px 40px;opacity:.2;pointer-events:none;z-index:0}
        #app{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column}
        nav{display:flex;align-items:center;gap:8px;padding:0 16px;height:var(--nav-h);background:rgba(10,10,15,.97);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;backdrop-filter:blur(12px)}
        .logo{font-family:var(--fh);font-size:22px;letter-spacing:3px;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;cursor:pointer;flex-shrink:0;white-space:nowrap}
        .logo span{-webkit-text-fill-color:var(--gold)}
        .sp{flex:1}
        .nb{background:none;border:1px solid var(--border);color:var(--text);font-family:var(--fb);font-size:12px;font-weight:700;padding:6px 12px;border-radius:var(--r);cursor:pointer;transition:all .2s;letter-spacing:.5px;text-transform:uppercase;white-space:nowrap;-webkit-appearance:none}
        .nb:hover,.nb:active{border-color:var(--accent);color:var(--accent)}
        .nb.p{background:var(--accent);border-color:var(--accent);color:#fff}
        .nb.p:hover,.nb.p:active{background:var(--accent2);border-color:var(--accent2)}
        .nb.admin-btn{border-color:var(--purple);color:var(--purple)}
        .nb.admin-btn:hover{background:var(--purple);color:#fff}
        .ubadge{display:flex;align-items:center;gap:6px;font-weight:700;font-size:12px;padding:5px 10px;border-radius:20px;background:var(--bg3);border:1px solid var(--border);cursor:pointer;white-space:nowrap}
        .ubadge.admin-badge{border-color:var(--purple)}
        .ubadge .pts{color:var(--gold);font-family:var(--fm);font-size:11px}
        .av{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:900;font-size:11px;color:#fff;flex-shrink:0}
        .av.admin-av{background:linear-gradient(135deg,var(--purple),var(--blue))}
        main{flex:1;padding:20px 16px;max-width:1080px;width:100%;margin:0 auto}
        .page{display:none;animation:fi .3s ease}
        .page.active{display:block}
        @keyframes fi{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        .card{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px}
        .ct{font-family:var(--fh);font-size:18px;letter-spacing:2px;margin-bottom:12px}
        .fg{margin-bottom:12px}
        .fg label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:5px}
        .fg input,.fg textarea,.fg select{width:100%;background:var(--bg3);border:1px solid var(--border);color:var(--text);font-family:var(--fb);font-size:15px;padding:10px 13px;border-radius:var(--r);outline:none;transition:border-color .2s;resize:vertical;-webkit-appearance:none;appearance:none}
        .fg input:focus,.fg textarea:focus{border-color:var(--accent)}
        .fg textarea{min-height:70px}
        .fr{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .cbrow{display:flex;align-items:center;gap:10px;cursor:pointer}
        .cbrow input[type=checkbox]{width:18px;height:18px;accent-color:var(--accent);cursor:pointer;flex-shrink:0}
        .cbrow span{font-size:14px;color:var(--muted)}
        .fic-info{background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.2);border-radius:var(--r);padding:10px;margin-top:8px;font-size:13px;color:var(--blue);display:none}
        .charter-box{background:rgba(255,149,0,.07);border:1px solid rgba(255,149,0,.3);border-radius:var(--r);padding:14px 16px;margin-bottom:16px}
        .charter-box .charter-title{font-family:var(--fh);font-size:15px;letter-spacing:2px;color:var(--orange);margin-bottom:8px;display:flex;align-items:center;gap:8px}
        .charter-box p{font-size:13px;color:var(--muted);line-height:1.6}
        .charter-box p strong{color:var(--text)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:var(--bg3);border:1px solid var(--border);color:var(--text);font-family:var(--fb);font-size:14px;font-weight:700;padding:10px 20px;border-radius:var(--r);cursor:pointer;text-transform:uppercase;letter-spacing:1px;transition:all .2s;-webkit-appearance:none;touch-action:manipulation;user-select:none}
        .btn:hover,.btn:active{border-color:var(--muted)}
        .btn-p{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:0 0 18px rgba(255,45,85,.3)}
        .btn-p:hover,.btn-p:active{background:var(--accent2);border-color:var(--accent2)}
        .btn-g{background:var(--gold);border-color:var(--gold);color:#000}
        .btn-gh{background:transparent}
        .btn-sm{font-size:11px;padding:6px 12px}
        .btn-bl{width:100%}
        .btn:disabled{opacity:.4;cursor:not-allowed}
        .btn-danger{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-warn{background:var(--orange);border-color:var(--orange);color:#000}
        .btn-purple{background:var(--purple);border-color:var(--purple);color:#fff}
        .btn-success{background:var(--green);border-color:var(--green);color:#000}
        .al{padding:10px 14px;border-radius:var(--r);font-size:13px;font-weight:600;margin-bottom:12px}
        .al-e{background:rgba(255,45,85,.15);border:1px solid rgba(255,45,85,.4);color:#ff6b8a}
        .al-s{background:rgba(57,255,20,.1);border:1px solid rgba(57,255,20,.3);color:var(--green)}
        .al-i{background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.3);color:var(--blue)}
        .al-w{background:rgba(255,149,0,.1);border:1px solid rgba(255,149,0,.3);color:var(--orange)}
        .hero{text-align:center;padding:36px 16px}
        .hero-t{font-family:var(--fh);font-size:clamp(48px,14vw,110px);letter-spacing:6px;line-height:1;background:linear-gradient(135deg,#fff 30%,var(--accent) 60%,var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}
        .hero-s{font-size:14px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;margin-bottom:28px}
        .hero-s em{color:var(--accent);font-style:normal}
        .hgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:24px}
        .hero-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .lbt{width:100%;border-collapse:collapse}
        .lbt th{text-align:left;padding:9px 13px;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);border-bottom:1px solid var(--border)}
        .lbt td{padding:11px 13px;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px}
        .lbt tr:hover td{background:rgba(255,255,255,.02)}
        .r1{color:var(--gold);font-family:var(--fh);font-size:19px}
        .r2{color:#c0c0c0;font-family:var(--fh);font-size:17px}
        .r3{color:#cd7f32;font-family:var(--fh);font-size:17px}
        .rn{color:var(--muted);font-family:var(--fm)}
        .odot{width:8px;height:8px;border-radius:50%;background:var(--muted);display:inline-block;margin-right:4px}
        .odot.on{background:var(--green);box-shadow:0 0 6px var(--green)}
        .ptb{font-family:var(--fm);color:var(--gold);font-size:12px}
        .ficb{font-size:10px;padding:2px 6px;border-radius:20px;background:rgba(0,212,255,.15);color:var(--blue);border:1px solid rgba(0,212,255,.3)}
        .game-wrap{display:flex;flex-direction:column;gap:14px}
        .gh{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:12px}
        .gp{text-align:center}
        .gp .gname{font-family:var(--fh);font-size:18px;letter-spacing:2px;line-height:1.1}
        .gp .gname.me{color:var(--accent)}
        .gp .gscore{font-family:var(--fm);font-size:28px;color:var(--gold)}
        .gvs{font-family:var(--fh);font-size:28px;color:var(--accent);letter-spacing:3px;text-align:center}
        .gprog{text-align:center;font-family:var(--fm);color:var(--muted);font-size:11px}
        .opp-info{background:var(--bg2);border:1px solid rgba(255,45,85,.25);border-radius:12px;overflow:hidden}
        .opp-info-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;cursor:pointer;user-select:none;background:rgba(255,45,85,.06);border-bottom:1px solid rgba(255,45,85,.15);touch-action:manipulation}
        .opp-info-header .title{font-family:var(--fh);font-size:15px;letter-spacing:2px;color:var(--accent);display:flex;align-items:center;gap:8px}
        .opp-info-toggle{color:var(--muted);font-size:18px;transition:transform .2s}
        .opp-info-toggle.open{transform:rotate(180deg)}
        .opp-info-body{padding:14px 16px;display:none}
        .opp-info-body.open{display:block}
        .opp-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .opp-item{background:var(--bg3);border-radius:8px;padding:10px 12px}
        .opp-item.wide{grid-column:1/-1}
        .opp-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:3px}
        .opp-value{font-size:13px;line-height:1.4;word-break:break-word}
        .fic-badge{display:inline-block;font-size:10px;padding:2px 7px;border-radius:10px;background:rgba(255,107,53,.2);color:var(--accent2);border:1px solid rgba(255,107,53,.3);margin-bottom:8px}
        .clog{display:flex;flex-direction:column;gap:10px;max-height:320px;overflow-y:auto;padding-right:2px}
        .clog::-webkit-scrollbar{width:3px}
        .clog::-webkit-scrollbar-track{background:var(--bg3)}
        .clog::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
        .cb{max-width:85%;padding:12px 14px;border-radius:12px}
        .cb.mine{align-self:flex-end;background:rgba(255,45,85,.15);border:1px solid rgba(255,45,85,.3)}
        .cb.theirs{align-self:flex-start;background:var(--bg3);border:1px solid var(--border)}
        .cb.bot-clash{align-self:flex-start;background:rgba(191,90,242,.1);border:1px solid rgba(191,90,242,.3)}
        .cb .bname{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:3px}
        .cb .btext{font-size:14px;line-height:1.5;word-break:break-word}
        .cb .bscore{margin-top:7px;font-family:var(--fm);font-size:11px;color:var(--gold)}
        .cb .bfb{font-size:11px;color:var(--muted);font-style:italic;margin-top:3px}
        .cb .ptag{display:inline-block;font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(255,45,85,.3);color:var(--accent);margin-top:4px;margin-right:4px}
        .cb .ftag{display:inline-block;font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(255,149,0,.3);color:var(--orange);margin-top:4px}
        .myturn{text-align:center;padding:10px;background:rgba(57,255,20,.08);border:1px solid rgba(57,255,20,.2);border-radius:var(--r);font-weight:700;font-size:13px;color:var(--green);text-transform:uppercase;letter-spacing:2px;animation:pulse 2s infinite}
        .waitb{text-align:center;padding:10px;background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.2);border-radius:var(--r);font-weight:700;font-size:13px;color:var(--blue);text-transform:uppercase;letter-spacing:1px}
        .bot-waitb{text-align:center;padding:10px;background:rgba(191,90,242,.08);border:1px solid rgba(191,90,242,.3);border-radius:var(--r);font-weight:700;font-size:13px;color:var(--purple);text-transform:uppercase;letter-spacing:1px;animation:pulse 1.5s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
        .clash-area{display:flex;flex-direction:column;gap:8px}
        .clash-input-row{display:flex;gap:8px;align-items:flex-end}
        .clash-input-row textarea{flex:1;min-height:100px;font-size:14px;background:var(--bg3);border:1px solid var(--border);color:var(--text);font-family:var(--fb);padding:10px 13px;border-radius:var(--r);outline:none;resize:vertical;transition:border-color .2s}
        .clash-input-row textarea:focus{border-color:var(--accent)}
        .send-btn{flex-shrink:0;width:72px;height:100px;background:var(--accent);border:none;border-radius:var(--r);color:#fff;font-family:var(--fh);font-size:13px;letter-spacing:1px;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;transition:background .2s;touch-action:manipulation}
        .send-btn:active{background:var(--accent2)}
        .cc{font-size:11px;color:var(--muted);text-align:right;font-family:var(--fm)}
        .gres{text-align:center;padding:36px 20px}
        .gres-t{font-family:var(--fh);font-size:clamp(42px,12vw,60px);letter-spacing:5px;margin-bottom:12px}
        .rwon{color:var(--gold)}.rlos{color:var(--muted)}.rdraw{color:var(--blue)}
        .mmwrap{display:flex;flex-direction:column;align-items:center;gap:22px;padding:50px 20px}
        .sring{width:72px;height:72px;border-radius:50%;border:3px solid var(--border);border-top-color:var(--accent);animation:spin 1s linear infinite}
        .sring-purple{width:72px;height:72px;border-radius:50%;border:3px solid var(--border);border-top-color:var(--purple);animation:spin 1s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        .lgc{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px;transition:border-color .2s}
        .lgc:hover{border-color:var(--accent)}
        .ldot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 7px var(--accent);animation:pulse 1.5s infinite;flex-shrink:0}
        .lgn{font-family:var(--fh);font-size:16px;letter-spacing:1px}
        .lgm{font-size:11px;color:var(--muted);font-family:var(--fm)}
        .lgs{font-family:var(--fm);font-size:17px;color:var(--gold);flex-shrink:0}
        .ph{display:flex;align-items:center;gap:16px;margin-bottom:20px}
        .pav{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;color:#fff;flex-shrink:0}
        .pname{font-family:var(--fh);font-size:28px;letter-spacing:3px}
        .pstats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
        .sb{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r);padding:12px;text-align:center}
        .sv{font-family:var(--fh);font-size:28px;letter-spacing:2px}
        .sl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
        .igrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .ik{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:3px}
        .iv{font-size:13px;line-height:1.4}
        .sbox{position:relative;margin-bottom:12px}
        .sbox input{padding-left:38px!important}
        .sicon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted)}
        .sri{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r);cursor:pointer;transition:all .2s;margin-bottom:6px}
        .sri:hover,.sri:active{border-color:var(--accent)}
        .sri-n{font-weight:700;flex:1;font-size:14px}
        .sri-p{font-family:var(--fm);color:var(--gold);font-size:11px}
        .tabs{display:flex;gap:2px;margin-bottom:20px;border-bottom:1px solid var(--border);overflow-x:auto}
        .tabs::-webkit-scrollbar{display:none}
        .tb{background:none;border:none;color:var(--muted);font-family:var(--fb);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:1px;padding:9px 16px;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .2s;white-space:nowrap;flex-shrink:0}
        .tb:hover{color:var(--text)}
        .tb.active{color:var(--accent);border-bottom-color:var(--accent)}
        .awrap{max-width:480px;margin:0 auto}
        .at{font-family:var(--fh);font-size:40px;letter-spacing:4px;text-align:center;margin-bottom:6px}
        .as{text-align:center;color:var(--muted);margin-bottom:24px;font-size:14px}
        .aswitch{text-align:center;margin-top:16px;color:var(--muted);font-size:13px}
        .aswitch a{color:var(--accent);cursor:pointer;font-weight:700}
        .slabel{font-size:10px;text-transform:uppercase;letter-spacing:3px;color:var(--muted);padding:7px 0;border-top:1px solid var(--border);margin:14px 0 10px}
        .admin-header{display:flex;align-items:center;gap:12px;margin-bottom:24px;padding:16px;background:rgba(191,90,242,.08);border:1px solid rgba(191,90,242,.3);border-radius:12px}
        .admin-badge-large{font-family:var(--fh);font-size:13px;letter-spacing:2px;padding:5px 12px;background:var(--purple);border-radius:20px;color:#fff}
        .admin-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:20px}
        .astat{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center}
        .astat .asv{font-family:var(--fh);font-size:32px}
        .astat .asl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
        .report-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:12px;transition:border-color .2s}
        .report-card.pending{border-color:rgba(255,149,0,.4)}
        .report-card.resolved{opacity:.6}
        .report-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
        .report-user{font-family:var(--fh);font-size:16px;letter-spacing:1px}
        .report-badge{font-size:10px;padding:3px 8px;border-radius:20px;font-weight:700;text-transform:uppercase}
        .rb-pending{background:rgba(255,149,0,.2);color:var(--orange);border:1px solid rgba(255,149,0,.4)}
        .rb-resolved{background:rgba(57,255,20,.1);color:var(--green);border:1px solid rgba(57,255,20,.3)}
        .rb-banned{background:rgba(255,45,85,.2);color:var(--accent);border:1px solid rgba(255,45,85,.4)}
        .report-content{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 13px;margin-bottom:10px;font-size:14px;line-height:1.5;border-left:3px solid var(--orange)}
        .report-reason{font-size:12px;color:var(--orange);margin-bottom:10px}
        .report-clash{font-size:12px;color:var(--muted);margin-bottom:8px;padding:8px 10px;background:var(--bg3);border-radius:6px;border-left:2px solid var(--accent)}
        .report-actions{display:flex;gap:7px;flex-wrap:wrap}
        .ban-form{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;margin-top:10px;display:none}
        .user-search-results{margin-top:12px;display:flex;flex-direction:column;gap:8px}
        .user-result{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:all .2s}
        .user-result:hover{border-color:var(--purple)}
        .user-result .ur-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--blue));display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;color:#fff;flex-shrink:0}
        .user-result .ur-info{flex:1}
        .user-result .ur-name{font-weight:700;font-size:14px}
        .user-result .ur-details{font-size:11px;color:var(--muted)}
        .user-result .ur-actions{display:flex;gap:6px}
        .banned-user-card{background:var(--bg2);border:1px solid rgba(255,45,85,.3);border-radius:10px;padding:12px 14px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:10px}
        .banned-user-info{flex:1}
        .banned-user-name{font-family:var(--fh);font-size:15px;letter-spacing:1px}
        .banned-user-reason{font-size:11px;color:var(--muted);margin-top:3px}
        .banned-user-type{font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(255,45,85,.2);color:var(--accent)}
        .ban-banner{background:rgba(255,45,85,.1);border:1px solid rgba(255,45,85,.4);border-radius:var(--r);padding:12px 16px;margin-bottom:12px}
        .ban-banner .ban-title{font-family:var(--fh);font-size:16px;letter-spacing:2px;color:var(--accent);margin-bottom:4px}
        .ban-banner p{font-size:13px;color:var(--muted)}
        /* Bot card dans la recherche */
        .bot-card{border-color:var(--purple)!important;background:rgba(191,90,242,.06)!important}
        .bot-card:hover,.bot-card:active{border-color:var(--purple)!important;background:rgba(191,90,242,.12)!important}
        .bot-hint{font-size:11px;color:var(--muted);padding:7px 12px;font-style:italic;border-left:2px solid var(--purple);margin-bottom:6px}
        .divider{height:1px;background:var(--border);margin:16px 0}
        .tm{color:var(--muted)}.ta{color:var(--accent)}.tg{color:var(--gold)}.tgr{color:var(--green)}.tb2{color:var(--blue)}
        .flex{display:flex;align-items:center;gap:10px}
        .fb{display:flex;align-items:center;justify-content:space-between;gap:10px}
        .mb{margin-bottom:14px}
        .page-title{font-family:var(--fh);font-size:30px;letter-spacing:3px;margin-bottom:20px}
        .loader{position:fixed;inset:0;background:rgba(10,10,15,.85);display:none;align-items:center;justify-content:center;z-index:999;backdrop-filter:blur(4px)}
        .loader.show{display:flex}
        .tc{position:fixed;bottom:16px;right:16px;display:flex;flex-direction:column;gap:7px;z-index:1000;max-width:calc(100vw - 32px)}
        .toast{padding:10px 16px;border-radius:var(--r);font-size:13px;font-weight:600;border:1px solid;animation:tIn .3s ease;max-width:300px;word-break:break-word}
        .toast.s{background:rgba(57,255,20,.15);border-color:rgba(57,255,20,.4);color:var(--green)}
        .toast.e{background:rgba(255,45,85,.15);border-color:rgba(255,45,85,.4);color:#ff6b8a}
        .toast.i{background:rgba(0,212,255,.1);border-color:rgba(0,212,255,.3);color:var(--blue)}
        .toast.p{background:rgba(191,90,242,.15);border-color:rgba(191,90,242,.4);color:var(--purple)}
        @keyframes tIn{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:translateX(0)}}
        .modal-overlay{position:fixed;inset:0;background:rgba(10,10,15,.87);z-index:200;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:16px}
        .modal-box{background:var(--bg2);border:1px solid var(--border);border-radius:12px;width:100%;max-width:400px;max-height:85vh;overflow-y:auto;padding:20px}
        .modal-box .ct{margin-bottom:14px}
        @media(max-width:600px){
            nav{padding:0 10px;gap:6px}.logo{font-size:18px;letter-spacing:2px}
            .nb{font-size:11px;padding:5px 9px}.ubadge{padding:4px 8px;gap:5px}
            .ubadge .uname{display:none}main{padding:14px 10px}
            .fr{grid-template-columns:1fr}.pstats{grid-template-columns:1fr 1fr}
            .igrid{grid-template-columns:1fr}
            .gh{grid-template-columns:1fr auto 1fr;gap:6px;padding:10px 12px}
            .gp .gname{font-size:14px;letter-spacing:1px}.gp .gscore{font-size:22px}.gvs{font-size:22px}
            .hero-t{font-size:clamp(42px,14vw,72px);letter-spacing:3px}.hero-s{font-size:12px;letter-spacing:2px}
            .btn{padding:10px 16px;font-size:13px}.opp-grid{grid-template-columns:1fr}
            .clog{max-height:260px}.cb{max-width:92%}
            .clash-input-row textarea{min-height:80px;font-size:13px}
            .send-btn{width:64px;height:80px;font-size:12px}
            .lbt th,.lbt td{padding:8px 8px;font-size:11px}.lbt .hide-mob{display:none}
            .page-title{font-size:24px}.admin-stats{grid-template-columns:1fr 1fr}
            .report-actions{flex-direction:column}.report-actions .btn{width:100%}
        }
        @media(max-width:380px){.nb.hide-xs{display:none}}
    </style>
</head>
<body>
<div id="app">
    <nav>
        <span class="logo" onclick="sp('home')">CLASH <span>⚡</span> FIGHT</span>
        <div class="sp"></div>
        <?php if ($user): ?>
            <?php if ($isAdmin): ?>
                <button class="nb admin-btn" onclick="sp('admin')">🛡 ADMIN</button>
                <div class="ubadge admin-badge" onclick="sp('admin')">
                    <div class="av admin-av"><?= h(strtoupper($user['username'][0])) ?></div>
                    <span class="uname"><?= h($user['username']) ?></span>
                </div>
            <?php else: ?>
                <button class="nb hide-xs" onclick="sp('live')">⚡ LIVE</button>
                <button class="nb hide-xs" onclick="sp('leaderboard')">🏆 TOP</button>
                <button class="nb" onclick="checkInvites()" id="ibtn">📨</button>
                <div class="ubadge" onclick="sp('profile')">
                    <div class="av"><?= h(strtoupper($user['username'][0])) ?></div>
                    <span class="uname"><?= h($user['username']) ?></span>
                    <span class="pts"><?= number_format($user['points']) ?> pts</span>
                </div>
            <?php endif; ?>
            <button class="nb" onclick="doLogout()">⏏</button>
        <?php else: ?>
            <button class="nb hide-xs" onclick="sp('leaderboard')">🏆 TOP</button>
            <button class="nb" onclick="sp('login')">CONNEXION</button>
            <button class="nb p" onclick="sp('register')">INSCR.</button>
        <?php endif; ?>
    </nav>

    <main>
        <!-- Home -->
        <div id="page-home" class="page active">
            <div class="hero">
                <div class="hero-t">CLASH FIGHT</div>
                <div class="hero-s">Le tournoi de <em>clashs</em> en temps réel</div>
                <?php if ($user && !$isAdmin): ?>
                    <div class="hero-btns">
                        <button class="btn btn-p" style="font-size:16px;padding:13px 36px" onclick="sp('play')">⚡ JOUER</button>
                        <button class="btn" onclick="sp('leaderboard')">🏆 Classement</button>
                        <button class="btn" onclick="sp('live')">👁 Live</button>
                    </div>
                <?php elseif (!$user): ?>
                    <div class="hero-btns">
                        <button class="btn btn-p" style="font-size:16px;padding:13px 36px" onclick="sp('register')">COMMENCER</button>
                        <button class="btn" onclick="sp('leaderboard')">🏆 Classement</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="hgrid">
                <div class="card">
                    <div class="ct">🎯 COMMENT JOUER</div>
                    <p class="tm" style="line-height:1.8;font-size:14px">Lance une game, utilise les infos perso de l'adversaire pour le clasher. L'IA note chaque clash sur 20. Le meilleur score gagne !</p>
                </div>
                <div class="card">
                    <div class="ct">⚠️ LES RÈGLES</div>
                    <p class="tm" style="line-height:1.8;font-size:14px">❌ Copier le clash adverse = <span class="ta">-<?= REPEAT_ENEMY_PENALTY ?> pts</span><br>⚠️ Réutiliser ton clash = <span class="tg">score ×<?= REPEAT_OWN_MULT ?></span><br>✅ L'originalité est récompensée !<br>🎲 Chaque partie : <span class="tb2">5 à 10 clashes</span> par joueur !</p>
                </div>
                <div class="card">
                    <div class="ct">📊 POINTS</div>
                    <p class="tm" style="line-height:1.8;font-size:14px">Victoire : <span class="tgr">+<?= BASE_WIN_POINTS ?> pts</span><br>Défaite : <span class="ta">-<?= BASE_LOSE_POINTS ?> pts</span><br>Matchmaking par niveau (±<?= MATCHMAKING_GAP ?> pts)<br>🤖 Mode bot = <span style="color:var(--purple)">local, sans classement</span></p>
                </div>
            </div>
        </div>

        <!-- Login -->
        <div id="page-login" class="page">
            <div class="awrap">
                <div class="at">CONNEXION</div>
                <div class="as">Prêt à clasher ?</div>
                <div class="card">
                    <div id="login-al"></div>
                    <div class="fg"><label>Pseudo ou email</label><input type="text" id="l-id" placeholder="ton_pseudo" autocomplete="username" inputmode="email"></div>
                    <div class="fg"><label>Mot de passe</label><input type="password" id="l-pw" placeholder="••••••••" autocomplete="current-password"></div>
                    <button class="btn btn-p btn-bl" onclick="submitLogin()">SE CONNECTER</button>
                </div>
                <div class="aswitch">Pas encore de compte ? <a onclick="sp('register')">S'inscrire</a></div>
            </div>
        </div>

        <!-- Register -->
        <div id="page-register" class="page">
            <div class="awrap">
                <div class="at">S'INSCRIRE</div>
                <div class="as">Rejoins l'arène</div>
                <div class="charter-box">
                    <div class="charter-title">⚖️ CHARTE DU CLASH</div>
                    <p>Clash Fight est un jeu de <strong>humour et de défi</strong> — gardons ça fun pour tout le monde !<br><br>
                    🚫 <strong>Seuls les contenus extrêmement graves sont interdits</strong> : insultes <strong>très lourdes</strong>, harcèlement <strong>explicite et violent</strong>, propos <strong>clairement racistes, homophobes ou sexistes</strong>, menaces <strong>directes et sérieuses</strong>.<br><br>
                    🤖 <strong>Chaque clash est analysé par l'IA</strong> : les contenus <strong>extrêmement inappropriés</strong> sont automatiquement signalés.<br><br>
                    ✅ Un bon clash, c'est créatif, piquant, drôle — pas une attaque personnelle réelle. <strong>Clash avec style, pas avec haine.</strong></p>
                </div>
                <div class="card">
                    <div id="reg-al"></div>
                    <div class="fr">
                        <div class="fg"><label>Pseudo *</label><input type="text" id="r-un" placeholder="CrashMaster" maxlength="30" autocomplete="username"></div>
                        <div class="fg"><label>Email *</label><input type="email" id="r-em" placeholder="toi@email.com" autocomplete="email" inputmode="email"></div>
                    </div>
                    <div class="fr">
                        <div class="fg"><label>Mot de passe *</label><input type="password" id="r-pw" placeholder="8 caractères min" autocomplete="new-password"></div>
                        <div class="fg"><label>Confirmer *</label><input type="password" id="r-pw2" placeholder="Répéter" autocomplete="new-password"></div>
                    </div>
                    <div class="slabel">Compte fictif ou réel ?</div>
                    <label class="cbrow" style="margin-bottom:8px">
                        <input type="checkbox" id="r-fic" onchange="toggleFic(this)">
                        <span>⚠️ Mes infos ci-dessous sont <strong>fictives</strong></span>
                    </label>
                    <div class="fic-info" id="fic-info">Ces informations fictives seront utilisées par tes adversaires pour te clasher. Invente quelque chose de fun !</div>
                    <div class="slabel">Infos personnelles (utilisées pour les clashs)</div>
                    <div class="fr">
                        <div class="fg"><label>Âge</label><input type="number" id="r-age" placeholder="22" min="1" max="120" inputmode="numeric"></div>
                        <div class="fg"><label>Ville</label><input type="text" id="r-city" placeholder="Paris" maxlength="100"></div>
                    </div>
                    <div class="fg"><label>Bio courte</label><textarea id="r-bio" placeholder="Décris-toi en quelques mots..." maxlength="300"></textarea></div>
                    <div class="fr">
                        <div class="fg"><label>Fun fact sur toi</label><input type="text" id="r-ff" placeholder="J'ai peur des pigeons 🐦" maxlength="200"></div>
                        <div class="fg"><label>Hobbies</label><input type="text" id="r-hob" placeholder="Gaming, cuisine..." maxlength="200"></div>
                    </div>
                    <label class="cbrow" style="margin-bottom:14px">
                        <input type="checkbox" id="r-accept" required>
                        <span>J'ai lu et j'accepte la <strong style="color:var(--accent)">charte du clash</strong> — je m'engage à jouer dans le respect.</span>
                    </label>
                    <button class="btn btn-p btn-bl" onclick="submitRegister()">CRÉER MON COMPTE</button>
                </div>
                <div class="aswitch">Déjà un compte ? <a onclick="sp('login')">Se connecter</a></div>
            </div>
        </div>

        <!-- Play -->
        <div id="page-play" class="page">
            <div class="fb mb">
                <h2 class="page-title" style="margin-bottom:0">JOUER</h2>
            </div>
            <div class="al al-i mb" style="font-size:13px">⏱️ <strong>Pas de limite de temps</strong> pour écrire ton clash — prends le temps de pondre quelque chose de créatif !<br>🎲 <strong>Nombre de clashes aléatoire</strong> : entre 5 et 10 clashes par joueur selon la partie !<br>🤖 <strong>Mode bot</strong> : tape <code style="background:rgba(191,90,242,.2);color:var(--purple);padding:1px 5px;border-radius:4px">bot</code> dans la recherche pour jouer contre l'IA (local, sans classement).</div>
            <div id="play-mm" class="hgrid">
                <div class="card">
                    <div class="ct">⚡ PARTIE RAPIDE</div>
                    <p class="tm mb" style="font-size:14px">Joue contre quelqu'un de ton niveau connecté maintenant.</p>
                    <button class="btn btn-p btn-bl" onclick="joinQueue()">LANCER !</button>
                </div>
                <div class="card">
                    <div class="ct">🔍 DÉFIER UN JOUEUR</div>
                    <p class="tm mb" style="font-size:14px">Cherche un pseudo et envoie-lui un défi. Tape <strong style="color:var(--purple)">bot</strong> pour jouer contre l'IA !</p>
                    <div class="sbox">
                        <span class="sicon">🔍</span>
                        <input type="text" id="si" placeholder="Chercher un pseudo... ou tape 'bot'" oninput="searchP(this.value)">
                    </div>
                    <div id="sr"></div>
                </div>
            </div>
            <div id="play-wait" style="display:none">
                <div class="mmwrap">
                    <div class="sring"></div>
                    <div style="font-family:var(--fh);font-size:24px;letter-spacing:3px">RECHERCHE...</div>
                    <div class="tm" style="font-size:14px">On cherche un adversaire de ton niveau</div>
                    <button class="btn" onclick="leaveQueue()">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Game -->
        <div id="page-game" class="page">
            <div id="game-content"></div>
        </div>

        <!-- Leaderboard -->
        <div id="page-leaderboard" class="page">
            <h2 class="page-title">🏆 CLASSEMENT</h2>
            <div class="card" style="padding:0;overflow:hidden">
                <table class="lbt">
                    <thead>
                        <tr>
                            <th>#</th><th>JOUEUR</th><th>POINTS</th>
                            <th class="hide-mob">V</th><th class="hide-mob">D</th><th>STATUT</th>
                        </tr>
                    </thead>
                    <tbody id="lb-body">
                        <tr><td colspan="6" style="text-align:center;padding:36px;color:var(--muted)">Chargement...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Live -->
        <div id="page-live" class="page">
            <h2 class="page-title">⚡ GAMES EN DIRECT</h2>
            <div id="live-list" style="display:flex;flex-direction:column;gap:10px">
                <div style="text-align:center;padding:36px;color:var(--muted)">Chargement...</div>
            </div>
        </div>

        <!-- Profile -->
        <div id="page-profile" class="page">
            <div id="profile-content"></div>
        </div>

        <!-- Admin -->
        <div id="page-admin" class="page">
            <div class="admin-header">
                <div class="admin-badge-large">🛡 ADMIN</div>
                <div>
                    <div style="font-family:var(--fh);font-size:20px;letter-spacing:2px">PANNEAU DE MODÉRATION</div>
                    <div style="font-size:12px;color:var(--muted)">ClashFightOfficiel — Gestion des signalements et des IP</div>
                </div>
            </div>
            <div class="admin-stats" id="admin-stats">
                <div class="astat"><div class="asv tg" id="as-users">—</div><div class="asl">Joueurs</div></div>
                <div class="astat"><div class="asv tgr" id="as-active">—</div><div class="asl">En ligne</div></div>
                <div class="astat"><div class="asv tb2" id="as-games">—</div><div class="asl">Parties</div></div>
                <div class="astat"><div class="asv ta" id="as-reports">—</div><div class="asl">Signalements en attente</div></div>
                <div class="astat"><div class="asv" style="color:var(--orange)" id="as-banned">—</div><div class="asl">Bannis</div></div>
                <div class="astat"><div class="asv" style="color:var(--purple)" id="as-banned-ips">—</div><div class="asl">IP Bannies</div></div>
            </div>
            <div class="tabs">
                <button class="tb active" onclick="switchAdminTab('reports',this)">🚩 Signalements</button>
                <button class="tb" onclick="switchAdminTab('search',this)">🔍 Rechercher un joueur</button>
                <button class="tb" onclick="switchAdminTab('banned',this)">🚫 Comptes bannis</button>
                <button class="tb" onclick="switchAdminTab('banned_ips',this)">🌐 IP Bannies</button>
            </div>
            <div id="admin-tab-reports" class="admin-tab">
                <div class="fb mb"><h2 class="page-title" style="margin-bottom:0">SIGNALEMENTS</h2><button class="btn btn-sm" onclick="loadAdminReports()">🔄 Actualiser</button></div>
                <div id="admin-reports"><div style="text-align:center;padding:36px;color:var(--muted)">Chargement...</div></div>
            </div>
            <div id="admin-tab-search" class="admin-tab" style="display:none">
                <div class="fb mb"><h2 class="page-title" style="margin-bottom:0">RECHERCHER UN JOUEUR</h2></div>
                <div class="card">
                    <div class="sbox"><span class="sicon">🔍</span><input type="text" id="admin-search-input" placeholder="Pseudo ou email..." oninput="adminSearchUsers(this.value)"></div>
                    <div id="admin-search-results" class="user-search-results"></div>
                </div>
            </div>
            <div id="admin-tab-banned" class="admin-tab" style="display:none">
                <div class="fb mb"><h2 class="page-title" style="margin-bottom:0">COMPTES BANNIS</h2><button class="btn btn-sm" onclick="loadBannedUsers()">🔄 Actualiser</button></div>
                <div id="banned-users-list"><div style="text-align:center;padding:36px;color:var(--muted)">Chargement...</div></div>
            </div>
            <div id="admin-tab-banned_ips" class="admin-tab" style="display:none">
                <div class="fb mb"><h2 class="page-title" style="margin-bottom:0">IP BANNIES</h2><button class="btn btn-sm" onclick="loadBannedIps()">🔄 Actualiser</button></div>
                <div class="card mb">
                    <div class="ct">🔒 BANNIR UNE IP MANUELLEMENT</div>
                    <div class="fr">
                        <div class="fg"><label>Adresse IP</label><input type="text" id="ban-ip-input" placeholder="Ex: 192.168.1.1"></div>
                        <div class="fg"><label>Raison</label><input type="text" id="ban-ip-reason" placeholder="Ex: Multi-comptes abusifs"></div>
                    </div>
                    <button class="btn btn-danger btn-bl" onclick="banIpManually()">🔨 BANNIR CETTE IP</button>
                </div>
                <div id="banned-ips-list"><div style="text-align:center;padding:36px;color:var(--muted)">Chargement...</div></div>
            </div>
        </div>
    </main>
</div>

<div class="loader" id="loader"><div class="sring"></div></div>
<div class="tc" id="toasts"></div>

<script>
const CSRF      = <?= json_encode($csrf) ?>;
const IS_LOGGED = <?= $user ? 'true' : 'false' ?>;
const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const ME        = <?= $user ? json_encode(['id' => $user['id'], 'username' => $user['username'], 'points' => $user['points']]) : 'null' ?>;
const WIN_PTS   = <?= BASE_WIN_POINTS ?>;
const LOSE_PTS  = <?= BASE_LOSE_POINTS ?>;

let currentAdminTab  = 'reports';
let savedClashText   = '';
let isSubmitting     = false;
let curGid           = null;
let gInt             = null;
let isWritingClash   = false;
let liveRefreshInterval = null;

// ── Utils ──────────────────────────────────────────────────
function g(id){ return document.getElementById(id); }
function showL(){ g('loader').classList.add('show'); }
function hideL(){ g('loader').classList.remove('show'); }
function toast(msg, type='i'){
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.textContent = msg;
    g('toasts').appendChild(t);
    setTimeout(()=>t.remove(), 4200);
}
function esc(s){
    if (!s && s!==0) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }

async function api(action, data={}, method='POST'){
    const isGet = method==='GET';
    let url = `?action=${action}`;
    let opts = { method, headers:{'X-Requested-With':'XMLHttpRequest'} };
    if (!isGet){
        const fd = new FormData();
        fd.append('csrf', CSRF);
        Object.entries(data).forEach(([k,v])=>fd.append(k,v));
        opts.body = fd;
    } else {
        Object.entries(data).forEach(([k,v])=>url+=`&${k}=${encodeURIComponent(v)}`);
    }
    const r = await fetch(url, opts);
    return r.json();
}

function setAl(id, msg, type){
    const el = g(id);
    if (!el) return;
    el.innerHTML = `<div class="al al-${type}">${esc(msg)}</div>`;
}

// Vérification périodique du ban
if (IS_LOGGED){
    setInterval(async ()=>{
        const r = await api('check_ban_status',{},'GET');
        if (r.ok && (r.banned || r.ip_banned)){
            toast('⚠️ Ton compte ou ton adresse IP a été banni(e). Tu vas être déconnecté.','e');
            setTimeout(()=>{ doLogout(); sp('login'); }, 3000);
        }
    }, 30000);
}

// ── Navigation ─────────────────────────────────────────────
let curPage = 'home';
function sp(page){
    if (page==='admin' && !IS_ADMIN){ toast('Accès refusé.','e'); return; }
    if (curPage==='live' && page!=='live'){
        if (liveRefreshInterval){ clearInterval(liveRefreshInterval); liveRefreshInterval=null; }
    }
    // Quitter le mode bot si on change de page
    if (page!=='game' && botGame){
        botGame = null;
        if (gInt){ clearInterval(gInt); gInt=null; }
    }
    document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
    const el = g(`page-${page}`);
    if (!el){ toast('Page introuvable','e'); return; }
    el.classList.add('active');
    curPage = page;
    if (page==='leaderboard') loadLB();
    if (page==='live') loadLive();
    if (page==='profile') loadProfile();
    if (page==='admin') loadAdminPage();
    if ((page==='play'||page==='game') && !IS_LOGGED){ sp('login'); return; }
    window.scrollTo(0,0);
}

// ── Admin Tabs ─────────────────────────────────────────────
function switchAdminTab(tab, btn){
    currentAdminTab = tab;
    document.querySelectorAll('.tb').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.admin-tab').forEach(t=>t.style.display='none');
    g(`admin-tab-${tab}`).style.display='block';
    if (btn) btn.classList.add('active');
    if (tab==='reports') loadAdminReports();
    if (tab==='search'){ g('admin-search-input').focus(); adminSearchUsers(g('admin-search-input').value); }
    if (tab==='banned') loadBannedUsers();
    if (tab==='banned_ips') loadBannedIps();
}

// ── Auth ───────────────────────────────────────────────────
async function submitLogin(){
    const login = g('l-id').value.trim();
    const pw    = g('l-pw').value;
    if (!login||!pw){ setAl('login-al','Remplis tous les champs.','e'); return; }
    showL();
    const r = await api('login',{login,password:pw});
    hideL();
    if (r.ok) location.reload();
    else setAl('login-al', r.error, 'e');
}

async function submitRegister(){
    if (!g('r-accept').checked){ setAl('reg-al','Tu dois accepter la charte du clash pour t\'inscrire.','e'); return; }
    const d = {
        username:g('r-un').value.trim(), email:g('r-em').value.trim(),
        password:g('r-pw').value, confirm:g('r-pw2').value,
        bio:g('r-bio').value, age:g('r-age').value,
        city:g('r-city').value, fun_fact:g('r-ff').value, hobbies:g('r-hob').value
    };
    if (g('r-fic').checked) d.is_fictional='1';
    showL();
    const r = await api('register', d);
    hideL();
    if (r.ok){ toast('Compte créé ! Connecte-toi.','s'); sp('login'); }
    else setAl('reg-al', r.error, 'e');
}

async function doLogout(){ await api('logout',{}); location.reload(); }

function toggleFic(cb){ g('fic-info').style.display = cb.checked ? 'block' : 'none'; }

// ── Leaderboard ────────────────────────────────────────────
async function loadLB(){
    const r = await api('leaderboard',{},'GET');
    if (!r.ok) return;
    const tb = g('lb-body');
    tb.innerHTML = r.players.map((p,i)=>{
        const rk=i+1, rc=rk===1?'r1':rk===2?'r2':rk===3?'r3':'rn';
        const onHtml = p.is_online
            ? `<span class="odot on"></span><span style="color:var(--green);font-size:11px">En ligne</span>`
            : `<span class="odot"></span><span style="color:var(--muted);font-size:11px">Offline</span>`;
        return `<tr>
            <td class="${rc}">${rk}</td>
            <td><strong>${esc(p.username)}</strong>${p.is_fictional?' <span class="ficb">FICTIF</span>':''}</td>
            <td class="ptb">${Number(p.points).toLocaleString()}</td>
            <td class="hide-mob" style="color:var(--green)">${p.wins}</td>
            <td class="hide-mob" style="color:var(--accent)">${p.losses}</td>
            <td>${onHtml}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="6" style="text-align:center;padding:36px;color:var(--muted)">Aucun joueur</td></tr>';
}

// ── Live ───────────────────────────────────────────────────
async function loadLive(){
    const r = await api('live_games',{},'GET');
    if (!r.ok) return;
    const el = g('live-list');
    if (!r.games.length){
        el.innerHTML='<div style="text-align:center;padding:36px;color:var(--muted)">Aucune game en cours</div>';
    } else {
        el.innerHTML = r.games.map(g2=>`
            <div class="lgc">
                <div class="ldot"></div>
                <div style="flex:1;min-width:0">
                    <div class="lgn">${esc(g2.p1)} <span style="color:var(--accent)">VS</span> ${esc(g2.p2)}</div>
                    <div class="lgm">Tour ${g2.current_turn}/${g2.total_rounds} — ${Math.ceil(g2.total_rounds/2)} clashes/joueur</div>
                </div>
                <div class="lgs">${parseFloat(g2.score_p1).toFixed(1)} — ${parseFloat(g2.score_p2).toFixed(1)}</div>
            </div>`).join('');
    }
    if (curPage==='live' && !liveRefreshInterval){
        liveRefreshInterval = setInterval(()=>{
            if (curPage==='live') loadLive();
            else { clearInterval(liveRefreshInterval); liveRefreshInterval=null; }
        }, 10000);
    }
}

// ── Profile ────────────────────────────────────────────────
async function loadProfile(uid=null){
    const params = uid ? {user_id:uid} : {};
    const r = await api('profile_get', params, 'GET');
    if (!r.ok){ g('profile-content').innerHTML='<div class="al al-e">Profil introuvable.</div>'; return; }
    const p  = r.profile;
    const isMe = ME && ME.id==p.id;
    let banHtml = '';
    if (p.ban_type==='temporary' && p.ban_until){
        const d = new Date(p.ban_until).toLocaleDateString('fr-FR');
        banHtml = `<div class="ban-banner"><div class="ban-title">🚫 Compte suspendu jusqu'au ${esc(d)}</div><p>Raison : ${esc(p.ban_reason||'Non précisée')}</p></div>`;
    } else if (p.ban_type==='permanent'){
        banHtml = `<div class="ban-banner"><div class="ban-title">🚫 Compte banni définitivement</div><p>Raison : ${esc(p.ban_reason||'Non précisée')}</p></div>`;
    }
    const infoItems = [
        p.age    ? `<div class="opp-item"><div class="opp-label">Âge</div><div class="opp-value">${esc(p.age)} ans</div></div>` : '',
        p.city   ? `<div class="opp-item"><div class="opp-label">Ville</div><div class="opp-value">${esc(p.city)}</div></div>` : '',
        p.bio    ? `<div class="opp-item wide"><div class="opp-label">Bio</div><div class="opp-value">${esc(p.bio)}</div></div>` : '',
        p.fun_fact ? `<div class="opp-item wide"><div class="opp-label">Fun Fact</div><div class="opp-value">${esc(p.fun_fact)}</div></div>` : '',
        p.hobbies  ? `<div class="opp-item wide"><div class="opp-label">Hobbies</div><div class="opp-value">${esc(p.hobbies)}</div></div>` : '',
    ].join('');
    g('profile-content').innerHTML = `
        ${banHtml}
        <div class="ph">
            <div class="pav">${esc(p.username[0].toUpperCase())}</div>
            <div>
                <div class="pname">${esc(p.username)}${p.is_fictional?' <span class="ficb">FICTIF</span>':''}</div>
                <div class="tm" style="font-size:12px">Membre depuis ${new Date(p.created_at).toLocaleDateString('fr-FR')}</div>
            </div>
        </div>
        <div class="pstats">
            <div class="sb"><div class="sv tg">${Number(p.points).toLocaleString()}</div><div class="sl">Points</div></div>
            <div class="sb"><div class="sv tgr">${p.wins}</div><div class="sl">Victoires</div></div>
            <div class="sb"><div class="sv ta">${p.losses}</div><div class="sl">Défaites</div></div>
        </div>
        <div class="card mb">
            <div class="ct">INFORMATIONS</div>
            ${p.is_fictional?'<div class="al al-i">⚠️ Infos fictives / inventées</div>':''}
            <div class="igrid">${infoItems||'<p class="tm" style="font-size:13px">Aucune info renseignée.</p>'}</div>
        </div>
        ${isMe
            ? `<button class="btn btn-gh" onclick="showEditP()">✏️ Modifier mon profil</button><div id="ep-form" style="display:none;margin-top:14px"></div>`
            : `<button class="btn btn-p" onclick="sendInv('${esc(p.username)}')">⚔️ Défier ${esc(p.username)}</button>`
        }`;
}

function showEditP(){
    const el = g('ep-form');
    if (!el) return;
    el.style.display = el.style.display==='none' ? 'block' : 'none';
    el.innerHTML = `<div class="card">
        <div class="ct">MODIFIER MON PROFIL</div>
        <div class="fr">
            <div class="fg"><label>Âge</label><input type="number" id="ep-age" min="1" max="120" inputmode="numeric"></div>
            <div class="fg"><label>Ville</label><input type="text" id="ep-city" maxlength="100"></div>
        </div>
        <div class="fg"><label>Bio</label><textarea id="ep-bio" maxlength="300"></textarea></div>
        <div class="fr">
            <div class="fg"><label>Fun Fact</label><input type="text" id="ep-ff" maxlength="200"></div>
            <div class="fg"><label>Hobbies</label><input type="text" id="ep-hob" maxlength="200"></div>
        </div>
        <button class="btn btn-p btn-bl" onclick="saveP()">SAUVEGARDER</button>
    </div>`;
}

async function saveP(){
    const d = { bio:g('ep-bio').value, age:g('ep-age').value, city:g('ep-city').value, fun_fact:g('ep-ff').value, hobbies:g('ep-hob').value };
    showL();
    const r = await api('profile_update', d);
    hideL();
    if (r.ok){ toast('Profil mis à jour !','s'); loadProfile(); }
    else toast(r.error,'e');
}

// ── Search (avec détection "bot") ──────────────────────────
let st = null;
async function searchP(q){
    clearTimeout(st);
    const sr = g('sr');
    if (q.length < 1){ sr.innerHTML=''; return; }

    // ── Commande spéciale : "bot" ──
    if (q.trim().toLowerCase() === 'bot'){
        sr.innerHTML = `
            <div class="bot-hint">💡 Tape <strong>bot</strong> pour lancer une partie locale contre l'IA — sans impact sur ton classement !</div>
            <div class="sri bot-card" onclick="startBotGame()">
                <div class="av" style="width:32px;height:32px;font-size:14px;background:linear-gradient(135deg,var(--purple),var(--blue));flex-shrink:0">🤖</div>
                <div class="sri-n" style="color:var(--purple)">ClashBot IA</div>
                <span style="font-size:10px;color:var(--purple);padding:2px 8px;border:1px solid var(--purple);border-radius:20px;white-space:nowrap">IA LOCAL</span>
                <div class="sri-p" style="color:var(--muted)">5 clashs chacun</div>
                <button class="btn btn-sm" style="background:var(--purple);border-color:var(--purple);color:#fff;flex-shrink:0">DÉFIER</button>
            </div>`;
        return;
    }

    if (q.length < 2){ sr.innerHTML=''; return; }

    st = setTimeout(async()=>{
        const r = await api('search_player',{q},'GET');
        if (!r.ok) return;
        sr.innerHTML = r.players.map(p=>`
            <div class="sri" onclick="sendInv('${esc(p.username)}')">
                <div class="av" style="width:28px;height:28px;font-size:11px">${esc(p.username[0].toUpperCase())}</div>
                <div class="sri-n">${esc(p.username)}</div>
                <span class="odot${p.is_online?' on':''}"></span>
                <div class="sri-p">${Number(p.points).toLocaleString()} pts</div>
                <button class="btn btn-p btn-sm">DÉFIER</button>
            </div>`).join('') || '<div class="tm" style="padding:10px;font-size:14px">Aucun résultat</div>';
    }, 280);
}

async function sendInv(username){
    showL();
    const r = await api('invite_send',{target:username});
    hideL();
    if (r.ok) toast(`Invitation envoyée à ${username} !`,'s');
    else toast(r.error,'e');
}

// ── Invitations ────────────────────────────────────────────
async function checkInvites(){
    const r = await api('get_invites',{},'GET');
    if (!r.ok||!r.invites.length){ toast('Aucune invitation en attente.','i'); return; }
    const invHtml = r.invites.map(inv=>`
        <div style="padding:12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;margin-bottom:8px">
            <div style="font-weight:700;margin-bottom:8px">⚔️ ${esc(inv.from_name)} te défie !</div>
            <div style="display:flex;gap:7px">
                <button class="btn btn-p btn-sm" onclick="accInv(${inv.id})">ACCEPTER</button>
                <button class="btn btn-sm" onclick="decInv(${inv.id})">Refuser</button>
            </div>
        </div>`).join('');
    const overlay = document.createElement('div');
    overlay.className='modal-overlay'; overlay.id='imod';
    overlay.innerHTML=`<div class="modal-box"><div class="ct">📨 INVITATIONS</div>${invHtml}<button class="btn btn-bl" style="margin-top:10px" onclick="g('imod').remove()">FERMER</button></div>`;
    document.body.appendChild(overlay);
}

async function accInv(id){
    showL(); const r = await api('invite_accept',{invite_id:id}); hideL();
    g('imod')?.remove();
    if (r.ok){ toast('Invitation acceptée !','s'); loadGame(r.game_id); }
    else toast(r.error,'e');
}

async function decInv(id){
    await api('invite_decline',{invite_id:id});
    g('imod')?.remove(); toast('Invitation refusée.','i');
}

// ── Matchmaking ────────────────────────────────────────────
let qi = null;
async function joinQueue(){
    if (!IS_LOGGED){ sp('login'); return; }
    showL(); const r = await api('queue_join',{}); hideL();
    if (!r.ok){ toast(r.error,'e'); return; }
    if (r.matched){ toast('Adversaire trouvé !','s'); loadGame(r.game_id); return; }
    g('play-mm').style.display='none';
    g('play-wait').style.display='block';
    qi = setInterval(pollQ, 3000);
}

async function pollQ(){
    const r = await api('queue_check',{},'GET');
    if (r.matched){
        clearInterval(qi);
        g('play-wait').style.display='none';
        g('play-mm').style.display='grid';
        toast('Adversaire trouvé !','s');
        loadGame(r.game_id);
    }
}

async function leaveQueue(){
    clearInterval(qi);
    await api('queue_leave',{});
    g('play-wait').style.display='none';
    g('play-mm').style.display='grid';
}

// ── Game (multijoueur) ─────────────────────────────────────
async function loadGame(gid){
    botGame = null; // on s'assure que le mode bot est éteint
    curGid = gid;
    savedClashText = '';
    isWritingClash = false;
    sp('game');
    await refreshGame();
    if (gInt) clearInterval(gInt);
    gInt = setInterval(refreshGame, 4000);
}

async function refreshGame(){
    if (!curGid || botGame) return;

    const inp = g('clash-inp');
    if (inp){
        savedClashText = inp.value;
        isWritingClash = (document.activeElement === inp);
    }

    const r = await api('game_state',{game_id:curGid},'GET');
    if (!r.ok) return;

    const game = r.game;
    const myId = ME ? ME.id : 0;

    const turn        = parseInt(game.current_turn);
    const expectedNow = (turn % 2 === 1)
        ? parseInt(game.player1_id)
        : parseInt(game.player2_id);
    const isNowMyTurn = (myId === expectedNow);

    if (isWritingClash && inp && isNowMyTurn && game.status === 'active'){
        updateGameWithoutInput(game, r.clashes, r.opponent_info);
    } else {
        renderGame(game, r.clashes, r.opponent_info);
    }
}

function updateGameWithoutInput(game, clashes, oppInfo){
    const myId  = ME ? ME.id : 0;
    const isP1  = ME && ME.id == game.player1_id;
    const myScore = isP1 ? game.score_p1 : game.score_p2;
    const opScore = isP1 ? game.score_p2 : game.score_p1;
    const turn  = parseInt(game.current_turn);
    const total = parseInt(game.total_rounds);

    const scoreEl1 = document.querySelector('.game-wrap .gp:first-child .gscore');
    const scoreEl2 = document.querySelector('.game-wrap .gp:last-child .gscore');
    const turnEl   = document.querySelector('.game-wrap .gprog');
    if (scoreEl1) scoreEl1.textContent = parseFloat(isP1 ? myScore : opScore).toFixed(1);
    if (scoreEl2) scoreEl2.textContent = parseFloat(isP1 ? opScore : myScore).toFixed(1);
    if (turnEl)   turnEl.textContent   = `Tour ${Math.min(turn,total)}/${total}`;

    const clog = g('game-content').querySelector('.clog');
    if (clog){
        clog.innerHTML = clashes.map(c=>{
            const isMe = c.player_id == myId;
            let tags='';
            if (c.is_repeat_enemy) tags+=`<span class="ptag">⚠️ Copié l'adversaire</span>`;
            if (c.is_repeat_own)   tags+=`<span class="ptag">⚠️ Déjà utilisé</span>`;
            if (c.is_flagged)      tags+=`<span class="ftag">🚩 Signalé</span>`;
            return `<div class="cb ${isMe?'mine':'theirs'}">
                <div class="bname">${esc(c.username)}</div>
                <div class="btext">${esc(c.content)}</div>
                ${c.ai_score!==null?`<div class="bscore">⭐ ${parseFloat(c.ai_score).toFixed(1)}/20</div>`:''}
                ${c.ai_feedback?`<div class="bfb">${esc(c.ai_feedback)}</div>`:''}
                ${tags}
            </div>`;
        }).join('') || `<div style="text-align:center;padding:28px;color:var(--muted);font-size:14px">⚡ La battle commence !</div>`;
        clog.scrollTop = clog.scrollHeight;
    }

    if (game.status === 'finished') renderGame(game, clashes, oppInfo);
}

function renderOppInfo(opp, oppName){
    if (!opp||!opp.username) return '';
    const hasAny = opp.age||opp.city||opp.bio||opp.fun_fact||opp.hobbies;
    if (!hasAny) return '';
    const items = [
        opp.age      ? `<div class="opp-item"><div class="opp-label">Âge</div><div class="opp-value">${esc(opp.age)} ans</div></div>` : '',
        opp.city     ? `<div class="opp-item"><div class="opp-label">Ville</div><div class="opp-value">${esc(opp.city)}</div></div>` : '',
        opp.bio      ? `<div class="opp-item wide"><div class="opp-label">Bio</div><div class="opp-value">${esc(opp.bio)}</div></div>` : '',
        opp.fun_fact ? `<div class="opp-item wide"><div class="opp-label">Fun Fact 🎯</div><div class="opp-value">${esc(opp.fun_fact)}</div></div>` : '',
        opp.hobbies  ? `<div class="opp-item wide"><div class="opp-label">Hobbies</div><div class="opp-value">${esc(opp.hobbies)}</div></div>` : '',
    ].join('');
    return `<div class="opp-info">
        <div class="opp-info-header" onclick="toggleOppInfo(this)">
            <div class="title">🎯 INFOS SUR ${esc(oppName.toUpperCase())} ${opp.is_fictional?'<span class="fic-badge">FICTIF</span>':''}</div>
            <div class="opp-info-toggle open">▼</div>
        </div>
        <div class="opp-info-body open">
            <p style="font-size:12px;color:var(--muted);margin-bottom:10px">Utilise ces infos pour personnaliser ton clash !</p>
            <div class="opp-grid">${items}</div>
        </div>
    </div>`;
}

function toggleOppInfo(header){
    const body   = header.nextElementSibling;
    const toggle = header.querySelector('.opp-info-toggle');
    body.classList.toggle('open');
    toggle.classList.toggle('open');
}

function renderGame(game, clashes, oppInfo){
    const myId  = ME ? ME.id : 0;
    const isP1  = ME && ME.id == game.player1_id;
    const myName  = isP1 ? game.p1_name : game.p2_name;
    const opName  = isP1 ? game.p2_name : game.p1_name;
    const myScore = isP1 ? game.score_p1 : game.score_p2;
    const opScore = isP1 ? game.score_p2 : game.score_p1;
    const turn    = parseInt(game.current_turn);
    const total   = parseInt(game.total_rounds);
    const clashesPerPlayer = Math.ceil(total / 2);
    const isOver  = game.status === 'finished';

    const expectedNow = (turn % 2 === 1)
        ? parseInt(game.player1_id)
        : parseInt(game.player2_id);
    const isMyTurn = (!isOver && myId === expectedNow);

    let html = `<div class="game-wrap">
        <div class="gh">
            <div class="gp">
                <div class="gname me">${esc(myName)} <span style="font-size:10px;color:var(--muted)">(toi)</span></div>
                <div class="gscore">${parseFloat(myScore).toFixed(1)}</div>
            </div>
            <div>
                <div class="gvs">VS</div>
                <div class="gprog">Tour ${Math.min(turn,total)}/${total} · ${clashesPerPlayer} clashes/joueur</div>
            </div>
            <div class="gp">
                <div class="gname">${esc(opName)}</div>
                <div class="gscore">${parseFloat(opScore).toFixed(1)}</div>
            </div>
        </div>`;

    if (!isOver) html += renderOppInfo(oppInfo, opName);

    html += `<div class="card" style="padding:14px"><div class="clog">`;
    if (!clashes.length){
        html += `<div style="text-align:center;padding:28px;color:var(--muted);font-size:14px">⚡ La battle commence !</div>`;
    }
    clashes.forEach(c=>{
        const isMe = c.player_id == myId;
        let tags='';
        if (c.is_repeat_enemy) tags+=`<span class="ptag">⚠️ Copié l'adversaire</span>`;
        if (c.is_repeat_own)   tags+=`<span class="ptag">⚠️ Déjà utilisé</span>`;
        if (c.is_flagged)      tags+=`<span class="ftag">🚩 Signalé</span>`;
        html+=`<div class="cb ${isMe?'mine':'theirs'}">
            <div class="bname">${esc(c.username)}</div>
            <div class="btext">${esc(c.content)}</div>
            ${c.ai_score!==null?`<div class="bscore">⭐ ${parseFloat(c.ai_score).toFixed(1)}/20</div>`:''}
            ${c.ai_feedback?`<div class="bfb">${esc(c.ai_feedback)}</div>`:''}
            ${tags}
        </div>`;
    });
    html += `</div></div>`;

    if (isOver){
        clearInterval(gInt);
        const iWon   = game.winner_id == myId;
        const isDraw = !game.winner_id;
        const resClass = iWon?'rwon':isDraw?'rdraw':'rlos';
        const resText  = iWon?'🏆 VICTOIRE !':isDraw?'🤝 ÉGALITÉ':'💀 DÉFAITE';
        const ptsTxt   = iWon?`+${WIN_PTS} points`:isDraw?`+${Math.floor(WIN_PTS/2)} points`:`-${LOSE_PTS} points`;
        html+=`<div class="gres">
            <div class="gres-t ${resClass}">${resText}</div>
            <div style="font-size:15px;color:var(--muted);margin-bottom:20px">${ptsTxt}</div>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <button class="btn btn-p" onclick="sp('play')">Rejouer</button>
                <button class="btn" onclick="sp('leaderboard')">🏆 Classement</button>
            </div>
        </div>`;
    } else if (isMyTurn){
        html+=`<div class="myturn">⚡ C'EST TON TOUR — CLASH !</div>
        <div class="al al-i" style="font-size:12px;text-align:center">⏱️ <strong>Pas de limite de temps</strong> — prends tout ton temps pour écrire !</div>
        <div class="clash-area">
            <div class="clash-input-row">
                <textarea
                    id="clash-inp"
                    placeholder="Écris ton clash... utilise ses infos ! Sois créatif, original, amusant !"
                    maxlength="500"
                    oninput="savedClashText=this.value;g('cc').textContent=this.value.length"
                    onfocus="isWritingClash=true"
                    onblur="isWritingClash=false"
                >${esc(savedClashText)}</textarea>
                <button class="send-btn" onclick="submitClash()" ${isSubmitting?'disabled':''}>
                    <span>⚡</span>ENVOYER
                </button>
            </div>
            <div class="cc"><span id="cc">${savedClashText.length}</span>/500</div>
        </div>`;
    } else {
        html+=`<div class="waitb">⏳ En attente du clash de ${esc(opName)}...</div>`;
    }

    html+=`</div>`;
    g('game-content').innerHTML = html;

    const newInp = g('clash-inp');
    if (newInp){
        newInp.value = savedClashText;
        g('cc').textContent = savedClashText.length;
        if (isMyTurn && !isOver) setTimeout(()=>newInp.focus(), 50);
    }

    const cl = g('game-content').querySelector('.clog');
    if (cl) cl.scrollTop = cl.scrollHeight;
}

async function submitClash(){
    const inp = g('clash-inp');
    if (!inp) return;
    const content = inp.value.trim();
    if (!content){ toast('Écris quelque chose !','e'); return; }
    if (isSubmitting) return;

    isSubmitting = true;
    inp.disabled = true;
    showL();

    const r = await api('clash_submit',{game_id:curGid, content});

    hideL();
    isSubmitting = false;
    inp.disabled = false;

    if (r.ok){
        if (r.flagged) toast('⚠️ Ton clash a été signalé pour contenu inapproprié.','e');
        savedClashText = '';
        isWritingClash = false;
        await refreshGame();
        setTimeout(()=>{ const ni=g('clash-inp'); if(ni&&!r.game_over) ni.focus(); }, 100);
    } else {
        toast(r.error||'Erreur serveur. Vérifie ta connexion.','e');
    }
}

// ══════════════════════════════════════════════════════════
// MODE BOT — Partie locale contre l'IA (non enregistrée)
// 5 clashs chacun = 10 tours au total, alternés
// Ni visible dans le live, ni enregistré en base
// ══════════════════════════════════════════════════════════

const BOT_NAME        = 'ClashBot IA';
const BOT_TOTAL_TURNS = 10; // 5 par joueur

// Profils fictifs variés pour le bot
const BOT_PROFILES = [
    {
        label: 'Le dev souffrant',
        age: 42, city: 'Paris',
        bio: 'Développeur PHP depuis 2003, vit dans son sous-sol.',
        fun_fact: "N'a pas vu le soleil depuis 2019 à cause des deadlines.",
        hobbies: 'Stack Overflow, café froid, rubber duck debugging.'
    },
    {
        label: "L'influenceur raté",
        age: 25, city: 'Bordeaux',
        bio: 'Influenceur Instagram avec 47 abonnés dont 30 bots.',
        fun_fact: "Croit sincèrement qu'il va percer bientôt.",
        hobbies: 'Selfies, #ad sponsorisés imaginaires, compter ses likes toutes les 2 minutes.'
    },
    {
        label: 'Le coach canapé',
        age: 55, city: 'Lyon',
        bio: "Coach sportif qui n'a pas couru depuis 3 ans.",
        fun_fact: 'Vend des programmes fitness depuis son canapé.',
        hobbies: 'Netflix, chips au fromage, commenter le sport sans en faire.'
    },
    {
        label: 'Le crypto-ruiné',
        age: 33, city: 'Lille',
        bio: 'Expert en crypto qui a tout perdu en 2022.',
        fun_fact: 'Dort encore avec son NFT de singe comme fond d\'écran.',
        hobbies: 'Lire des threads Twitter à 3h du mat, HODL, pleurer discrètement.'
    },
    {
        label: 'Le skateur raté',
        age: 17, city: 'Marseille',
        bio: 'Passe ses journées à tenter des tricks et à se vautrer.',
        fun_fact: 'A cassé 7 planches de skate en un mois.',
        hobbies: 'Skate (théoriquement), TikTok, kebab à 2h du matin.'
    },
];

let botGame = null; // état complet de la partie bot (local, en mémoire)

function startBotGame(){
    if (!IS_LOGGED){ sp('login'); return; }
    if (gInt){ clearInterval(gInt); gInt = null; }
    curGid = null;

    const profile = BOT_PROFILES[Math.floor(Math.random() * BOT_PROFILES.length)];

    botGame = {
        profile,
        turn: 1,             // 1=joueur, 2=bot, 3=joueur... jusqu'à BOT_TOTAL_TURNS
        totalTurns: BOT_TOTAL_TURNS,
        playerScore: 0,
        botScore: 0,
        clashes: [],         // {author:'player'|'bot', content, score, feedback, penaltyCopy, penaltyReuse}
        status: 'active',    // 'active' | 'finished'
        isSubmitting: false,
        savedText: '',
    };

    g('si').value = '';
    g('sr').innerHTML = '';
    sp('game');
    renderBotGame();
    toast('🤖 Partie contre le bot lancée — bonne chance !', 'p');
}

function botTargetInfo(){
    const p = botGame.profile;
    return `Pseudo:${BOT_NAME}|FICTIF|Bio:${p.bio}|Âge:${p.age}|Ville:${p.city}|Fun fact:${p.fun_fact}|Hobbies:${p.hobbies}`;
}

function playerTargetInfo(){
    return `Pseudo:${ME.username}|RÉEL|Bio:joueur humain en train de clasher un bot|Âge:inconnu|Ville:inconnue|Fun fact:a osé défier une IA|Hobbies:Clash Fight, perdre contre des bots`;
}

function renderBotGame(){
    if (!botGame){ return; }
    const bg     = botGame;
    const isOver = bg.status === 'finished';
    // Tour impair (1,3,5...) = joueur ; tour pair (2,4,6...) = bot
    const isMyTurn = !isOver && (bg.turn % 2 === 1);
    const clashesPerPlayer = bg.totalTurns / 2;

    let html = `<div class="game-wrap">

        <!-- Badge LOCAL -->
        <div style="text-align:center;margin-bottom:4px">
            <span style="font-size:11px;padding:4px 14px;border-radius:20px;background:rgba(191,90,242,.15);border:1px solid rgba(191,90,242,.4);color:var(--purple);font-weight:700;letter-spacing:1px">
                🤖 PARTIE LOCALE · Sans classement · Non visible en live
            </span>
        </div>

        <!-- Scores -->
        <div class="gh">
            <div class="gp">
                <div class="gname me">${esc(ME.username)} <span style="font-size:10px;color:var(--muted)">(toi)</span></div>
                <div class="gscore">${bg.playerScore.toFixed(1)}</div>
            </div>
            <div>
                <div class="gvs">VS</div>
                <div class="gprog">Tour ${Math.min(bg.turn, bg.totalTurns)}/${bg.totalTurns} · ${clashesPerPlayer} clashes/joueur</div>
            </div>
            <div class="gp">
                <div class="gname" style="color:var(--purple)">🤖 ${esc(BOT_NAME)}</div>
                <div class="gscore">${bg.botScore.toFixed(1)}</div>
            </div>
        </div>

        <!-- Profil du bot à clasher -->
        <div class="opp-info" style="border-color:rgba(191,90,242,.35)">
            <div class="opp-info-header" style="background:rgba(191,90,242,.08);border-bottom-color:rgba(191,90,242,.2)" onclick="toggleOppInfo(this)">
                <div class="title" style="color:var(--purple)">🎯 PROFIL À CLASHER — ${esc(bg.profile.label||BOT_NAME).toUpperCase()} <span class="fic-badge">FICTIF</span></div>
                <div class="opp-info-toggle open" style="color:var(--purple)">▼</div>
            </div>
            <div class="opp-info-body open">
                <p style="font-size:12px;color:var(--muted);margin-bottom:10px">Utilise ces infos pour personnaliser tes clashs !</p>
                <div class="opp-grid">
                    <div class="opp-item"><div class="opp-label">Âge</div><div class="opp-value">${bg.profile.age} ans</div></div>
                    <div class="opp-item"><div class="opp-label">Ville</div><div class="opp-value">${esc(bg.profile.city)}</div></div>
                    <div class="opp-item wide"><div class="opp-label">Bio</div><div class="opp-value">${esc(bg.profile.bio)}</div></div>
                    <div class="opp-item wide"><div class="opp-label">Fun Fact 🎯</div><div class="opp-value">${esc(bg.profile.fun_fact)}</div></div>
                    <div class="opp-item wide"><div class="opp-label">Hobbies</div><div class="opp-value">${esc(bg.profile.hobbies)}</div></div>
                </div>
            </div>
        </div>

        <!-- Log des clashs -->
        <div class="card" style="padding:14px"><div class="clog" id="bot-clog">`;

    if (!bg.clashes.length){
        html += `<div style="text-align:center;padding:28px;color:var(--muted);font-size:14px">⚡ Ouvre le feu — clash le bot !</div>`;
    }

    bg.clashes.forEach(c => {
        const isMe = c.author === 'player';
        let tags = '';
        if (c.penaltyCopy)  tags += `<span class="ptag">⚠️ Clash copié (-30 pts)</span>`;
        if (c.penaltyReuse) tags += `<span class="ptag">⚠️ Déjà utilisé (×0.5)</span>`;
        html += `<div class="cb ${isMe ? 'mine' : 'bot-clash'}">
            <div class="bname">${isMe ? esc(ME.username) : '🤖 ' + esc(BOT_NAME)}</div>
            <div class="btext">${esc(c.content)}</div>
            ${c.score !== null ? `<div class="bscore">⭐ ${parseFloat(c.score).toFixed(1)}/20</div>` : '<div class="bscore" style="color:var(--muted)">⏳ En cours de notation...</div>'}
            ${c.feedback ? `<div class="bfb">${esc(c.feedback)}</div>` : ''}
            ${tags}
        </div>`;
    });

    html += `</div></div>`;

    // Zone de saisie ou attente
    if (isOver){
        const iWon   = bg.playerScore > bg.botScore;
        const isDraw = Math.abs(bg.playerScore - bg.botScore) < 0.01;
        const resClass = iWon ? 'rwon' : isDraw ? 'rdraw' : 'rlos';
        const resText  = iWon ? '🏆 VICTOIRE !' : isDraw ? '🤝 ÉGALITÉ' : '💀 DÉFAITE';
        const diff     = Math.abs(bg.playerScore - bg.botScore).toFixed(1);
        const diffTxt  = iWon
            ? `Tu as explosé le bot de ${diff} pts`
            : isDraw
            ? 'Match nul — le bot t\'a bien résisté !'
            : `Le bot t'a dominé de ${diff} pts... Pathétique.`;
        html += `<div class="gres">
            <div class="gres-t ${resClass}">${resText}</div>
            <div style="font-size:15px;color:var(--muted);margin-bottom:6px">${diffTxt}</div>
            <div style="font-size:12px;color:var(--purple);margin-bottom:4px">Score final — Toi : ${bg.playerScore.toFixed(1)} | Bot : ${bg.botScore.toFixed(1)}</div>
            <div style="font-size:11px;color:var(--muted);margin-bottom:22px">Partie locale — Aucun point de classement modifié</div>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <button class="btn btn-p" onclick="startBotGame()">🔄 Revanche</button>
                <button class="btn btn-purple" onclick="sp('play')">← Menu</button>
                <button class="btn" onclick="sp('leaderboard')">🏆 Classement</button>
            </div>
        </div>`;
    } else if (isMyTurn){
        html += `<div class="myturn">⚡ C'EST TON TOUR — CLASH LE BOT !</div>
        <div class="al al-i" style="font-size:12px;text-align:center">⏱️ <strong>Pas de limite de temps</strong> — sois créatif et utilise les infos du bot !</div>
        <div class="clash-area">
            <div class="clash-input-row">
                <textarea
                    id="bot-clash-inp"
                    placeholder="Clash le bot avec ses infos... vise sa bio, ses hobbies, sa ville !"
                    maxlength="500"
                    oninput="botGame.savedText=this.value;g('bot-cc').textContent=this.value.length"
                >${esc(bg.savedText)}</textarea>
                <button class="send-btn" style="background:var(--purple)" onclick="submitBotClash()" ${bg.isSubmitting ? 'disabled' : ''}>
                    <span>⚡</span>ENVOYER
                </button>
            </div>
            <div class="cc"><span id="bot-cc">${bg.savedText.length}</span>/500</div>
        </div>`;
    } else {
        html += `<div class="bot-waitb">🤖 Le bot prépare sa réponse...</div>`;
    }

    html += `</div>`;
    g('game-content').innerHTML = html;

    // Scroll
    const clog = g('bot-clog');
    if (clog) clog.scrollTop = clog.scrollHeight;

    // Focus
    const inp = g('bot-clash-inp');
    if (inp && isMyTurn && !isOver) setTimeout(()=>inp.focus(), 80);
}

async function submitBotClash(){
    if (!botGame || botGame.isSubmitting || botGame.status !== 'active') return;

    const inp = g('bot-clash-inp');
    if (!inp) return;
    const content = inp.value.trim();
    if (!content){ toast('Écris quelque chose !','e'); return; }
    if (content.length < 5){ toast('Clash trop court !','e'); return; }

    botGame.isSubmitting = true;
    botGame.savedText    = '';
    showL();

    // ── Détection des répétitions ──
    const botContents    = botGame.clashes.filter(c=>c.author==='bot').map(c=>c.content.toLowerCase());
    const playerContents = botGame.clashes.filter(c=>c.author==='player').map(c=>c.content.toLowerCase());
    const penaltyCopy    = botContents.includes(content.toLowerCase());
    const penaltyReuse   = playerContents.includes(content.toLowerCase());

    // Ajouter le clash du joueur provisoirement (score null pendant la notation)
    const clashEntry = { author:'player', content, score:null, feedback:'', penaltyCopy, penaltyReuse };
    botGame.clashes.push(clashEntry);
    botGame.turn++;
    renderBotGame(); // Affiche le clash avec "en cours de notation"
    hideL();

    // ── Notation via le serveur (Mistral) ──
    const lastBotClash = [...botGame.clashes].reverse().find(c=>c.author==='bot');
    const prevClash    = lastBotClash ? lastBotClash.content : '';

    const scored = await botScoreClash(content, botTargetInfo(), prevClash);
    let score = scored.score;
    if (penaltyCopy)  score = Math.max(0, score - 30);
    if (penaltyReuse) score = Math.round(score * 0.5 * 10) / 10;

    // Mise à jour du score dans le clash
    clashEntry.score    = score;
    clashEntry.feedback = scored.feedback;
    botGame.playerScore += score;

    // Si partie terminée après le tour du joueur
    if (botGame.turn > botGame.totalTurns){
        botGame.status       = 'finished';
        botGame.isSubmitting = false;
        renderBotGame();
        return;
    }

    botGame.isSubmitting = false;
    renderBotGame();

    // ── Tour du bot ──
    await doBotTurn();
}

async function doBotTurn(){
    if (!botGame || botGame.status !== 'active') return;

    // Pause réaliste : le bot "réfléchit" (1.5 à 3.5 secondes)
    await sleep(1500 + Math.random() * 2000);

    showL();

    // Génération du clash du bot
    const lastPlayerClash = [...botGame.clashes].reverse().find(c=>c.author==='player');
    const prevPlayerClash = lastPlayerClash ? lastPlayerClash.content : '';

    const botClashContent = await botGenerateClash(playerTargetInfo(), prevPlayerClash);

    // Notation du clash du bot
    const scoredBot = await botScoreClash(botClashContent, playerTargetInfo(), prevPlayerClash);

    // Vérif répétitions côté bot
    const botPrev = botGame.clashes.filter(c=>c.author==='bot').map(c=>c.content.toLowerCase());
    const penaltyCopyBot  = botPrev.includes(botClashContent.toLowerCase());
    let botScore = scoredBot.score;
    if (penaltyCopyBot) botScore = Math.max(0, botScore - 30);

    botGame.clashes.push({
        author: 'bot',
        content: botClashContent,
        score: botScore,
        feedback: scoredBot.feedback,
        penaltyCopy: penaltyCopyBot,
        penaltyReuse: false,
    });
    botGame.botScore += botScore;
    botGame.turn++;

    hideL();

    if (botGame.turn > botGame.totalTurns){
        botGame.status = 'finished';
    }

    renderBotGame();
}

// ── Appel serveur : noter un clash (sans l'enregistrer) ──
async function botScoreClash(clash, targetInfo, prevClash = ''){
    try {
        const r = await api('bot_score_clash', {
            clash,
            target_info: targetInfo,
            prev_clash: prevClash
        });
        if (r.ok) return { score: r.score, feedback: r.feedback };
    } catch(e){}
    // Fallback si erreur
    return { score: 10.0, feedback: 'Score automatique (IA indisponible).' };
}

// ── Appel serveur : générer le clash du bot via Mistral ──
async function botGenerateClash(targetInfo, lastPlayerClash = ''){
    const profile = botGame.profile;
    const botProfileStr = `Pseudo:${BOT_NAME}|Profil:${profile.label}|Âge:${profile.age}|Ville:${profile.city}`;
    try {
        const r = await api('bot_generate_clash', {
            target_info: targetInfo,
            last_player_clash: lastPlayerClash,
            bot_profile: botProfileStr
        });
        if (r.ok && r.clash) return r.clash;
    } catch(e){}
    // Fallback drôle si erreur réseau
    const fallbacks = [
        "Même ton routeur se déconnecte quand il te voit arriver... et lui au moins il a une excuse.",
        "T'as le charisme d'un message d'erreur 404. Introuvable, et personne ne te cherche.",
        "Ton clash était si prévisible que l'IA l'a deviné avant que tu l'écrives. Et elle bâillait déjà.",
        "Tu clasher, c'est comme regarder un Pokémon de niveau 1 défier un dragon. Touchant, mais pathétique.",
    ];
    return fallbacks[Math.floor(Math.random() * fallbacks.length)];
}

// ── Admin ──────────────────────────────────────────────────
async function loadAdminPage(){
    if (!IS_ADMIN) return;
    const s = await api('admin_stats',{},'GET');
    if (s.ok){
        g('as-users').textContent    = s.total_users;
        g('as-active').textContent   = s.active_now;
        g('as-games').textContent    = s.total_games;
        g('as-reports').textContent  = s.pending_reports;
        g('as-banned').textContent   = s.banned_users;
        g('as-banned-ips').textContent = s.banned_ips;
    }
    if (currentAdminTab==='reports')    loadAdminReports();
    if (currentAdminTab==='search')     adminSearchUsers('');
    if (currentAdminTab==='banned')     loadBannedUsers();
    if (currentAdminTab==='banned_ips') loadBannedIps();
}

async function loadAdminReports(){
    const r  = await api('admin_reports',{},'GET');
    const el = g('admin-reports');
    if (!r.ok){ el.innerHTML='<div class="al al-e">Erreur chargement.</div>'; return; }
    if (!r.reports.length){ el.innerHTML='<div style="text-align:center;padding:36px;color:var(--muted)">✅ Aucun signalement en attente</div>'; return; }
    el.innerHTML = r.reports.map(rep=>{
        const isPending = rep.status==='pending';
        const isBanned  = rep.ban_type==='permanent'||rep.ban_type==='temporary';
        const statusBadge = isPending
            ? `<span class="report-badge rb-pending">⏳ En attente</span>`
            : `<span class="report-badge rb-resolved">✅ Traité : ${esc(rep.admin_action||'—')}</span>`;
        const banBadge = isBanned ? `<span class="report-badge rb-banned">🚫 Banni</span>` : '';
        return `<div class="report-card ${isPending?'pending':'resolved'}" id="rep-${rep.id}">
            <div class="report-meta">
                <div class="report-user">👤 ${esc(rep.username)}</div>
                ${statusBadge}${banBadge}
                <span style="font-size:11px;color:var(--muted);margin-left:auto">${new Date(rep.created_at).toLocaleString('fr-FR')}</span>
            </div>
            <div class="report-reason">🚩 <strong>Raison</strong> : ${esc(rep.flag_reason)}</div>
            <div class="report-clash">💬 <strong>Clash signalé</strong> : "${esc(rep.clash_content)}"</div>
            <div class="report-clash" style="margin-top:5px">🎮 <strong>Game ID</strong> : ${rep.game_id} | <strong>Joueur</strong> : ${esc(rep.username)}</div>
            ${isPending?`
            <div class="report-actions">
                <button class="btn btn-success btn-sm" onclick="resolveReport(${rep.id},'Acquitté : Pas de sanction')">✅ Acquitter</button>
                <button class="btn btn-warn btn-sm" onclick="showBanForm(${rep.id})">⏳ Ban temporaire</button>
                <button class="btn btn-danger btn-sm" onclick="showPermBan(${rep.id},${rep.user_id},'${esc(rep.username)}')">🔨 Ban définitif</button>
                <button class="btn btn-sm" style="border-color:var(--purple);color:var(--purple)" onclick="deleteAccount(${rep.id},${rep.user_id},'${esc(rep.username)}')">🗑️ Supprimer compte</button>
            </div>
            <div class="ban-form" id="banform-${rep.id}">
                <div class="fg">
                    <label>Raison du ban (visible par le joueur)</label>
                    <input type="text" id="ban-reason-${rep.id}" placeholder="Ex: Propos racistes lors d'une partie" value="${esc(rep.flag_reason)}">
                </div>
                <div class="fg">
                    <label>Suspendu jusqu'au (YYYY-MM-DD HH:MM)</label>
                    <input type="text" id="ban-until-${rep.id}" placeholder="2026-12-31 23:59" value="${rep.ban_until||''}">
                </div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button class="btn btn-warn btn-sm" onclick="doTempBan(${rep.id},${rep.user_id})">✅ Confirmer ban temporaire</button>
                    <button class="btn btn-sm" onclick="g('banform-${rep.id}').style.display='none'">❌ Annuler</button>
                </div>
            </div>`:''}
        </div>`;
    }).join('');
}

function showBanForm(repId){
    const el = g(`banform-${repId}`);
    if (el) el.style.display = el.style.display==='none' ? 'block' : 'none';
}

async function doTempBan(repId, userId){
    const reason = g(`ban-reason-${repId}`).value.trim();
    const until  = g(`ban-until-${repId}`).value.trim();
    if (!reason){ toast('La raison est obligatoire.','e'); return; }
    if (!until){  toast('La date de fin est obligatoire (format: YYYY-MM-DD HH:MM).','e'); return; }
    showL();
    const r1 = await api('admin_ban',{user_id:userId,ban_type:'temporary',reason,ban_until:until});
    if (r1.ok){
        await resolveReport(repId,`Ban temporaire jusqu'au ${until} | Raison: ${reason}`);
        toast(`✅ Suspendu jusqu'au ${until}.`,'s');
        loadAdminReports(); loadBannedUsers();
    } else toast(r1.error,'e');
    hideL();
}

async function showPermBan(repId, userId, username){
    const reason = prompt(`Raison du ban définitif pour ${username} (visible sur son profil) :`);
    if (!reason) return;
    showL();
    const r1 = await api('admin_ban',{user_id:userId,ban_type:'permanent',reason});
    if (r1.ok){
        await resolveReport(repId,`Ban définitif | Raison: ${reason}`);
        toast(`🔨 ${esc(username)} banni définitivement.`,'s');
        loadAdminReports(); loadBannedUsers();
    } else toast(r1.error,'e');
    hideL();
}

async function deleteAccount(repId, userId, username){
    if (!confirm(`⚠️ Supprimer définitivement le compte de ${username} ?\nCette action est IRRÉVERSIBLE.`)) return;
    showL();
    const r1 = await api('admin_delete',{user_id:userId});
    if (r1.ok){
        await resolveReport(repId,'Compte supprimé par admin');
        toast(`🗑️ Compte de ${username} supprimé.`,'s');
        loadAdminReports(); loadBannedUsers();
    } else toast(r1.error,'e');
    hideL();
}

async function resolveReport(repId, actionNote){
    const r = await api('admin_resolve',{report_id:repId,action_note:actionNote});
    if (r.ok){ toast('✅ Signalement traité.','s'); loadAdminReports(); }
    else toast(r.error,'e');
    return r;
}

let adminSearchTimeout=null;
async function adminSearchUsers(q){
    clearTimeout(adminSearchTimeout);
    const el = g('admin-search-results');
    if (q.length<1){ el.innerHTML='<div style="text-align:center;padding:20px;color:var(--muted)">🔍 Entrez un pseudo ou email...</div>'; return; }
    adminSearchTimeout = setTimeout(async()=>{
        const r = await api('admin_search_users',{q},'GET');
        if (!r.ok){ el.innerHTML='<div class="al al-e">Erreur de recherche.</div>'; return; }
        if (!r.users.length){ el.innerHTML='<div style="text-align:center;padding:20px;color:var(--muted)">Aucun utilisateur trouvé.</div>'; return; }
        el.innerHTML = r.users.map(u=>{
            const isBanned = u.ban_type==='permanent'||u.ban_type==='temporary';
            const banStatus = isBanned
                ? `<span style="background:rgba(255,45,85,.2);color:var(--accent);padding:2px 6px;border-radius:4px;font-size:10px">${u.ban_type==='permanent'?'🚫 Banni déf.':'⏳ Suspendu'}</span>`
                : `<span style="color:var(--green);font-size:10px">✅ Actif</span>`;
            return `<div class="user-result">
                <div class="ur-av">${esc(u.username[0].toUpperCase())}</div>
                <div class="ur-info">
                    <div class="ur-name">${esc(u.username)} ${u.is_online?'<span class="odot on"></span>':''}</div>
                    <div class="ur-details">📧 ${esc(u.email)} | 🏆 ${Number(u.points).toLocaleString()} pts | ${banStatus}</div>
                </div>
                <div class="ur-actions">
                    ${!isBanned
                        ? `<button class="btn btn-danger btn-sm" onclick="adminQuickBan(${u.id},'${esc(u.username)}','permanent')">🔨 Ban déf.</button>
                           <button class="btn btn-warn btn-sm" onclick="adminQuickBan(${u.id},'${esc(u.username)}','temporary')">⏳ Ban temp.</button>`
                        : `<button class="btn btn-success btn-sm" onclick="adminUnban(${u.id},'${esc(u.username)}')">✅ Débannir</button>`
                    }
                    <button class="btn btn-purple btn-sm" onclick="viewUserProfile(${u.id})">👤 Voir profil</button>
                </div>
            </div>`;
        }).join('');
    }, 300);
}

async function adminQuickBan(userId, username, type){
    let reason, until=null;
    if (type==='temporary'){
        until  = prompt(`Jusqu'à quand suspendre ${username} ? (YYYY-MM-DD HH:MM)\nExemple: 2026-12-31 23:59`);
        if (!until) return;
        reason = prompt(`Raison du ban temporaire pour ${username} :`);
        if (!reason) return;
    } else {
        reason = prompt(`Raison du ban définitif pour ${username} :`);
        if (!reason) return;
    }
    showL();
    const r = await api('admin_ban',{user_id:userId,ban_type:type,reason,ban_until:type==='temporary'?until:null});
    hideL();
    if (r.ok){
        toast(`${username} ${type==='permanent'?'🚫 banni définitivement':'⏳ suspendu jusqu\'au '+until}.`,'s');
        adminSearchUsers(g('admin-search-input').value);
        loadBannedUsers();
    } else toast(r.error,'e');
}

async function adminUnban(userId, username){
    if (!confirm(`Débannir ${username} ?`)) return;
    showL();
    const r = await api('admin_ban',{user_id:userId,ban_type:'unban',reason:''});
    hideL();
    if (r.ok){
        toast(`✅ ${username} débanni.`,'s');
        adminSearchUsers(g('admin-search-input').value);
        loadBannedUsers();
    } else toast(r.error,'e');
}

function viewUserProfile(userId){ loadProfile(userId); sp('profile'); }

async function loadBannedUsers(){
    const r  = await api('admin_banned_users',{},'GET');
    const el = g('banned-users-list');
    if (!r.ok){ el.innerHTML='<div class="al al-e">Erreur de chargement.</div>'; return; }
    if (!r.banned.length){ el.innerHTML='<div style="text-align:center;padding:36px;color:var(--muted)">✅ Aucun compte banni</div>'; return; }
    el.innerHTML = r.banned.map(u=>{
        const banTypeText = u.ban_type==='permanent'
            ? '🚫 Banni définitivement'
            : `⏳ Suspendu jusqu'au ${new Date(u.ban_until).toLocaleString('fr-FR')}`;
        return `<div class="banned-user-card">
            <div class="banned-user-info">
                <div class="banned-user-name">${esc(u.username)}</div>
                <div class="banned-user-reason">${banTypeText} | <strong>Raison</strong> : ${esc(u.ban_reason||'Non précisée')}</div>
            </div>
            <button class="btn btn-success btn-sm" onclick="adminUnban(${u.id},'${esc(u.username)}')">✅ Débannir</button>
        </div>`;
    }).join('');
}

async function loadBannedIps(){
    const r  = await api('admin_get_banned_ips',{},'GET');
    const el = g('banned-ips-list');
    if (!r.ok){ el.innerHTML='<div class="al al-e">Erreur de chargement.</div>'; return; }
    if (!r.banned_ips.length){ el.innerHTML='<div style="text-align:center;padding:36px;color:var(--muted)">✅ Aucune IP bannie</div>'; return; }
    el.innerHTML = r.banned_ips.map(ip=>`
        <div class="banned-user-card">
            <div class="banned-user-info">
                <div class="banned-user-name" style="font-family:var(--fm);font-size:14px">${esc(ip.ip_address)}</div>
                <div class="banned-user-reason">Banni le ${new Date(ip.banned_at).toLocaleString('fr-FR')} | <strong>Raison</strong> : ${esc(ip.reason||'Non précisée')}</div>
            </div>
            <button class="btn btn-success btn-sm" onclick="unbanIp('${esc(ip.ip_address)}')">✅ Débannir</button>
        </div>`).join('');
}

async function banIpManually(){
    const ip     = g('ban-ip-input').value.trim();
    const reason = g('ban-ip-reason').value.trim();
    if (!ip){ toast('L\'adresse IP est obligatoire.','e'); return; }
    showL();
    const r = await api('admin_ban_ip',{ip, reason:reason||'Ban manuel par admin'});
    hideL();
    if (r.ok){
        toast(`🔒 IP ${ip} bannie avec succès.`,'s');
        g('ban-ip-input').value=''; g('ban-ip-reason').value='';
        loadBannedIps();
        const s = await api('admin_stats',{},'GET');
        if (s.ok) g('as-banned-ips').textContent=s.banned_ips;
    } else toast(r.error||'Erreur lors du ban de l\'IP.','e');
}

async function unbanIp(ip){
    if (!confirm(`Débannir l'IP ${ip} ?`)) return;
    showL();
    const r = await api('admin_unban_ip',{ip});
    hideL();
    if (r.ok){
        toast(`✅ IP ${ip} débannie.`,'s');
        loadBannedIps();
        const s = await api('admin_stats',{},'GET');
        if (s.ok) g('as-banned-ips').textContent=s.banned_ips;
    } else toast(r.error||'Erreur lors du débannissement.','e');
}

// ── Divers ─────────────────────────────────────────────────
if (IS_LOGGED) setInterval(()=>api('check_ban_status',{},'GET'), 60000);

document.addEventListener('keydown', e=>{
    if (e.key==='Enter' && curPage==='login' && document.activeElement.tagName!=='TEXTAREA') submitLogin();
});

if (IS_ADMIN) setTimeout(()=>sp('admin'), 100);
</script>
</body>
</html>