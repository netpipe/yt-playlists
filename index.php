<style>
#bannerimage {
  width: 300px;
  background-image: url('banner.png');
  height: 100px;
  background-position: center;
}
body {
  background-image: url('background.png');
}
</style>

<?php
session_start();
$db = new PDO('sqlite:data.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $hash = hash('sha256', $_COOKIE['remember_token']);
    $now = time();
    $stmt = $db->prepare("SELECT user_id FROM user_tokens WHERE token = ? AND expires > ?");
    $stmt->execute([$hash, $now]);
    $uid = $stmt->fetchColumn();
    if ($uid) {
        $_SESSION['user_id'] = $uid;
    } else {
        // Invalid or expired
        setcookie('remember_token', '', time() - 3600, "/");
    }
}

// Create tables if not exist
$db->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password TEXT
);
CREATE TABLE IF NOT EXISTS playlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    url TEXT,
    title TEXT,
    score INTEGER DEFAULT 0,
    last_active INTEGER,
    created INTEGER
);
CREATE TABLE IF NOT EXISTS votes (
    user_id INTEGER,
    playlist_id INTEGER,
    UNIQUE(user_id, playlist_id)
);
CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    playlist_id INTEGER,
    user_id INTEGER,
    content TEXT,
    created INTEGER
);
CREATE TABLE IF NOT EXISTS user_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    token TEXT,
    expires INTEGER
);
");
function generate_token($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

// Utility
function is_logged_in() {
    return isset($_SESSION['user_id']);
}
function get_user($id) {
    global $db;
    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}
function is_valid_playlist_url($url) {
    return preg_match('/^(https?:\/\/)?([\w\-]+\.)*youtube\.com\/.*[?&]list=([\w\-]+)/i', $url);
}


// Register
if (isset($_POST['register'])) {
    $u = trim($_POST['username']);
    $p = password_hash($_POST['password'], PASSWORD_DEFAULT);
    try {
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$u, $p]);
        echo "Registered! Please log in.<br>";
    } catch (PDOException $e) {
        echo "Username already taken.<br>";
    }
}

// Login
if (isset($_POST['login'])) {
    $u = trim($_POST['username']);
    $p = $_POST['password'];
    $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$u]);
    $row = $stmt->fetch();
    if ($row && password_verify($p, $row['password'])) {
$_SESSION['user_id'] = $row['id'];

if (!empty($_POST['remember'])) {
    $token = generate_token();
    $expires = time() + (86400 * 30); // 30 days
    setcookie('remember_token', $token, $expires, "/", "", false, true); // HttpOnly
    $hash = hash('sha256', $token);
    $db->prepare("INSERT INTO user_tokens (user_id, token, expires) VALUES (?, ?, ?)")->execute([$row['id'], $hash, $expires]);
}

    } else {
        echo "Login failed.<br>";
    }
}

// Logout
if (isset($_GET['logout'])) {
if (isset($_COOKIE['remember_token'])) {
    $hash = hash('sha256', $_COOKIE['remember_token']);
    $db->prepare("DELETE FROM user_tokens WHERE token = ?")->execute([$hash]);
    setcookie('remember_token', '', time() - 3600, "/");
}
session_destroy();
header("Location: ?");
exit;

}

// Add playlist
if (is_logged_in() && isset($_POST['add'])) {
    $url = trim($_POST['url']);
    $title = trim($_POST['title']);
    $now = time();
    if (!is_valid_playlist_url($url)) {
        echo "<p style='color:red;'>Invalid YouTube playlist URL. Must contain '?list='</p>";
    } else {
        $stmt = $db->prepare("INSERT INTO playlists (user_id, url, title, created, last_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $url, $title, $now, $now]);
    }
}

// Click to rank
if (is_logged_in() && isset($_GET['rank'])) {
    $id = (int)$_GET['rank'];
    $now = time();
    $stmt = $db->prepare("UPDATE playlists SET score = score + 1, last_active = ? WHERE id = ?");
    $stmt->execute([$now, $id]);
}

// Vote (thumbs up)
if (is_logged_in() && isset($_GET['vote'])) {
    $pid = (int)$_GET['vote'];
    $uid = $_SESSION['user_id'];

    // Check if already voted
    $stmt = $db->prepare("SELECT 1 FROM votes WHERE user_id = ? AND playlist_id = ?");
    $stmt->execute([$uid, $pid]);

    if (!$stmt->fetch()) {
        $db->prepare("INSERT INTO votes (user_id, playlist_id) VALUES (?, ?)")->execute([$uid, $pid]);
        $db->prepare("UPDATE playlists SET score = score + 1, last_active = ? WHERE id = ?")->execute([time(), $pid]);
    }

    header("Location: ?");
    exit;
}

// Comment
if (is_logged_in() && isset($_POST['comment'])) {
    $pid = (int)$_POST['playlist_id'];
    $content = trim($_POST['content']);
    if ($content !== '') {
        $stmt = $db->prepare("INSERT INTO comments (playlist_id, user_id, content, created) VALUES (?, ?, ?, ?)");
        $stmt->execute([$pid, $_SESSION['user_id'], $content, time()]);
    }
}

// Delete Playlist
if (is_logged_in() && isset($_GET['delete_playlist'])) {
    $id = (int)$_GET['delete_playlist'];
    $stmt = $db->prepare("DELETE FROM playlists WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $db->prepare("DELETE FROM comments WHERE playlist_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM votes WHERE playlist_id = ?")->execute([$id]);
}

// Delete Comment
if (is_logged_in() && isset($_GET['delete_comment'])) {
    $id = (int)$_GET['delete_comment'];
    $stmt = $db->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
}

// Expire old playlists (5 years = 157788000 seconds)
$db->exec("DELETE FROM playlists WHERE last_active < " . (time() - 157788000));

?>
<!DOCTYPE html>
<html>
<head><title>YouTube Playlist Share</title>
<style>
body { font-family: sans-serif; max-width: 700px; margin: auto; }
input, textarea { width: 100%; padding: 4px; margin: 4px 0; }
form { margin-bottom: 1em; }
.playlist { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; }
.comment { margin-left: 20px; font-size: 0.9em; color: #555; }
</style>
</head>
<body>
<h2>YouTube Playlist Sharing</h2>

<?php if (!is_logged_in()): ?>
<form method="post"><h3>Register</h3>
<input name="username" placeholder="Username">
<input type="password" name="password" placeholder="Password">
<button name="register">Register</button>
</form>

<form method="post"><h3>Login</h3>
<input name="username" placeholder="Username">
<input type="password" name="password" placeholder="Password">
<button name="login">Login</button>
<label><input type="checkbox" name="remember"> Remember me</label>

</form>
<?php else: ?>
<p>Logged in as <?=htmlspecialchars(get_user($_SESSION['user_id']))?> | <a href="?logout=1">Logout</a></p>
<form method="post"><h3>Share a Playlist</h3>
<input name="title" placeholder="Title" required>
<input name="url" placeholder="YouTube Playlist URL (must contain ?list=...)" required>
<button name="add">Add Playlist</button>
</form>
<?php endif; ?>

<div id="bannerimage"></div>

<h3>Playlists</h3>
<?php
$playlists = $db->query("SELECT * FROM playlists ORDER BY score DESC")->fetchAll();
foreach ($playlists as $p):
?>
<div class="playlist">

<b><?=htmlspecialchars($p['title'])?></b><br>
<a href="<?=htmlspecialchars($p['url'])?>" target="_blank"><?=htmlspecialchars($p['url'])?></a><br>
Score: <?=$p['score']?> |
<?php if (is_logged_in()): ?>
 <a href="?vote=<?=$p['id']?>">vote ↑</a> |
 <a href="?vote=<?=$p['id']?>">👍</a>
 <?php if ($p['user_id'] == $_SESSION['user_id']): ?>
  | <a href="?delete_playlist=<?=$p['id']?>" onclick="return confirm('Delete this playlist?')">🗑 Delete</a>
 <?php endif; ?>
<?php endif; ?>

<br><b>Comments:</b>
<?php
$cs = $db->prepare("SELECT c.*, u.username FROM comments c JOIN users u ON c.user_id = u.id WHERE playlist_id = ? ORDER BY created DESC");
$cs->execute([$p['id']]);
foreach ($cs as $c):
?>
<div class="comment">
<b><?=htmlspecialchars($c['username'])?>:</b>
<?=htmlspecialchars($c['content'])?>
<?php if (is_logged_in() && $c['user_id'] == $_SESSION['user_id']): ?>
 <a href="?delete_comment=<?=$c['id']?>" onclick="return confirm('Delete this comment?')">🗑</a>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php if (is_logged_in()): ?>
<form method="post">
<input type="hidden" name="playlist_id" value="<?=$p['id']?>">
<textarea name="content" placeholder="Add a comment..." required></textarea>
<button name="comment">Comment</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
<a href="https://github.com/netpipe/yt-playlists">🕸Pproject Page🕸</a>
</body>
</html>
