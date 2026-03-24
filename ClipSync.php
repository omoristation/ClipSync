<?php
/**
 * 极简通用剪切板 (PHP 8.0+ 优化版)
 * 1. 首次运行设置密码
 * 2. 扫码免密登录
 * 3. 长轮询实时同步
 */

error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set('Asia/Shanghai');

define('DATA_DIR', __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('AUTH_COOKIE', 'UC_AUTH_TOKEN');

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

// --- 基础函数 ---

function get_config() {
    return is_file(CONFIG_FILE) ? json_decode(file_get_contents(CONFIG_FILE), true) : null;
}

function save_config($data) {
    file_put_contents(CONFIG_FILE, json_encode($data));
}

function is_authenticated(): bool {
    $config = get_config();
    if (!$config || !isset($_COOKIE[AUTH_COOKIE])) return false;
    return $_COOKIE[AUTH_COOKIE] === $config['cookie_token'];
}

// 获取/保存内容
function access_clip(string $text = null): array {
    $file = DATA_DIR . '/clip.json';
    if ($text !== null) {
        $data = ['text' => $text, 'mtime' => (int)(microtime(true) * 1000)];
        file_put_contents($file, json_encode($data));
        return $data;
    }
    return is_file($file) ? json_decode(file_get_contents($file), true) : ['text' => '', 'mtime' => 0];
}

// --- 路由逻辑 ---

$config = get_config();
$is_first_run = ($config === null);

// 1. 设置初始密码
if ($is_first_run && isset($_POST['new_password'])) {
    $token = bin2hex(random_bytes(16));
    save_config([
        'password' => password_hash($_POST['new_password'], PASSWORD_DEFAULT),
        'cookie_token' => $token
    ]);
    setcookie(AUTH_COOKIE, $token, time() + 86400 * 30, '/', '', false, true);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 2. 登录逻辑
if (isset($_POST['login_password']) && !$is_first_run) {
    if (password_verify($_POST['login_password'], $config['password'])) {
        setcookie(AUTH_COOKIE, $config['cookie_token'], time() + 86400 * 30, '/', '', false, true);
        header("Location: " . $_SERVER['PHP_SELF']);
    } else {
        $error = "密码错误";
    }
}

// 3. 扫码登录 (Token 校验)
if (isset($_GET['token']) && !$is_first_run) {
    $stored = access_clip(); // 临时借用内容存储校验 Token
    if (isset($stored['login_token']) && $_GET['token'] === $stored['login_token']) {
        if (time() - ($stored['token_time'] ?? 0) < 300) {
            setcookie(AUTH_COOKIE, $config['cookie_token'], time() + 86400 * 30, '/', '', false, true);
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    die("登录链接已过期，请刷新网页重新扫码");
}

// 4. 登出
if (isset($_GET['logout'])) {
    setcookie(AUTH_COOKIE, '', time() - 3600, '/');
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 5. 实时同步 API (长轮询)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_authenticated()) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (($input['action'] ?? '') === 'push') {
        echo json_encode(access_clip($input['text']));
    } else {
        $last_mtime = (int)($input['mtime'] ?? 0);
        for ($i = 0; $i < 50; $i++) { // 约 25 秒长轮询
            $current = access_clip();
            if ($current['mtime'] > $last_mtime) {
                echo json_encode($current);
                exit;
            }
            usleep(500000);
            clearstatcache();
        }
        echo json_encode(['mtime' => $last_mtime]);
    }
    exit;
}

// 生成动态登录 Token (仅 PC 端登录后可见)
$qr_url = "";
if (is_authenticated()) {
    $login_token = bin2hex(random_bytes(8));
    $data = access_clip();
    $data['login_token'] = $login_token;
    $data['token_time'] = time();
    file_put_contents(DATA_DIR . '/clip.json', json_encode($data));
    $qr_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?token=$login_token";
}

?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClipSync</title>
    <style>
        :root { --bg: #121212; --card: #1e1e1e; --text: #e0e0e0; --accent: #03dac6; }
        body { background: var(--bg); color: var(--text); font-family: system-ui, -apple-system; display: flex; justify-content: center; padding: 20px; }
        .card { background: var(--card); padding: 2rem; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.4); width: 100%; max-width: 800px; }
        textarea { width: 100%; height: 400px; background: #2c2c2c; color: #fff; border: none; border-radius: 8px; padding: 15px; font-size: 16px; resize: none; margin: 15px 0; outline: none; }
        textarea:focus { box-shadow: 0 0 0 2px var(--accent); }
        input[type="password"] { width: 100%; padding: 12px; margin: 10px 0; background: #2c2c2c; border: 1px solid #444; color: #fff; border-radius: 4px; box-sizing: border-box; }
        button { background: var(--accent); color: #000; border: none; padding: 12px 24px; border-radius: 4px; font-weight: bold; cursor: pointer; width: 100%; }
        .status { font-size: 12px; color: #888; display: flex; justify-content: space-between; align-items: center; }
        #qrcode { background: #fff; padding: 10px; border-radius: 8px; display: inline-block; margin-top: 10px; }
        a { color: var(--accent); text-decoration: none; font-size: 14px; }
    </style>
    <script src="https://cdn.bootcdn.net/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
<div class="card">
    <?php if ($is_first_run): ?>
        <h3>初始化密码</h3>
        <form method="post">
            <input type="password" name="new_password" placeholder="请设置访问密码" required autofocus>
            <button type="submit">保存并登录</button>
        </form>
    <?php elseif (!is_authenticated()): ?>
        <h3>身份验证</h3>
        <form method="post">
            <input type="password" name="login_password" placeholder="输入访问密码" required autofocus>
            <button type="submit">进入剪切板</button>
            <?php if(isset($error)) echo "<p style='color:red'>$error</p>"; ?>
        </form>
    <?php else: ?>
        <div class="status">
            <span id="state">正在连接...</span>
            <a href="?logout=1">退出登录</a>
        </div>
        <textarea id="editor" placeholder="在此输入，即刻同步..."></textarea>
        <div style="text-align: center;">
            <p style="font-size:12px; color:#666;">手机扫码免密登录</p>
            <div id="qrcode"></div>
        </div>

        <script>
            const editor = document.getElementById('editor');
            const state = document.getElementById('state');
            let lastMtime = 0;
            let typing = false;
            let timer;

            // 二维码
            new QRCode(document.getElementById("qrcode"), { text: "<?php echo $qr_url; ?>", width: 140, height: 140 });

            async function sync() {
                try {
                    const r = await fetch('', {
                        method: 'POST',
                        body: JSON.stringify({ action: 'pull', mtime: lastMtime })
                    });
                    const d = await r.json();
                    if (d.mtime > lastMtime) {
                        lastMtime = d.mtime;
                        if (!typing) editor.value = d.text;
                        state.innerText = "上次同步: " + new Date().toLocaleTimeString();
                    }
                    sync();
                } catch (e) { setTimeout(sync, 2000); }
            }

            editor.oninput = () => {
                typing = true;
                state.innerText = "正在输入...";
                clearTimeout(timer);
                timer = setTimeout(async () => {
                    const r = await fetch('', {
                        method: 'POST',
                        body: JSON.stringify({ action: 'push', text: editor.value })
                    });
                    const d = await r.json();
                    lastMtime = d.mtime;
                    typing = false;
                    state.innerText = "已保存";
                }, 600);
            };

            sync();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
