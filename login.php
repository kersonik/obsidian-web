<?php
session_start();

// Handle logout
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

// Redirect if already logged in (only for GET requests, allow POST to switch mode)
if (!empty($_SESSION['logged_in']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$attemptsFile = __DIR__ . '/ip_attempts.json';
$maxAttempts = 5;
$lockoutDuration = 1800; // 30 minutes in seconds

// Load attempts data
$attemptsData = [];
if (file_exists($attemptsFile)) {
    $json = file_get_contents($attemptsFile);
    $attemptsData = json_decode($json, true) ?: [];
}

$now = time();

// Check if IP is locked out
if (isset($attemptsData[$ip])) {
    $ipInfo = $attemptsData[$ip];
    if ($ipInfo['attempts'] >= $maxAttempts) {
        $elapsed = $now - $ipInfo['last_attempt'];
        if ($elapsed < $lockoutDuration) {
            $remainingMins = ceil(($lockoutDuration - $elapsed) / 60);
            $error = "Přístup zablokován z důvodu příliš mnoha neúspěšných pokusů (5/5). Zkuste to znovu za {$remainingMins} minut.";
        } else {
            // Lockout expired, reset attempts
            unset($attemptsData[$ip]);
            file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));
        }
    }
}

// Process login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password'] ?? '');

    if (!empty($error)) {
        // Already blocked
    } else {
        // Bcrypt hashes for 090112 and nevimcodelam
        $readonlyHash = '$2y$10$dEwXPfdu5MVgN1BJXN7kT.WTOvdztbi.GWRgcpmXETleK026QzIA.';
        $editHash     = '$2b$10$DRel7GW6aFx0KtmbYinQTOIQlMFL/tPFX6QQhTodW7GQqFySB5c8.';

        $isReadonly = password_verify($password, $readonlyHash) || ($password === '090112');
        $isEdit     = password_verify($password, $editHash) || ($password === 'nevimcodelam');

        if ($isReadonly) {
            // Read-Only Login
            if (isset($attemptsData[$ip])) {
                unset($attemptsData[$ip]);
                file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));
            }
            $_SESSION['logged_in'] = true;
            $_SESSION['mode'] = 'readonly';
            session_write_close();
            header("Location: index.php");
            exit;
        } else if ($isEdit) {
            // Edit Mode Login
            if (isset($attemptsData[$ip])) {
                unset($attemptsData[$ip]);
                file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));
            }
            $_SESSION['logged_in'] = true;
            $_SESSION['mode'] = 'edit';
            session_write_close();
            header("Location: index.php");
            exit;
        } else {
            // Failed attempt: increment counter
            if (!isset($attemptsData[$ip])) {
                $attemptsData[$ip] = ['attempts' => 0, 'last_attempt' => 0];
            }
            $attemptsData[$ip]['attempts']++;
            $attemptsData[$ip]['last_attempt'] = $now;
            file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));

            $count = $attemptsData[$ip]['attempts'];
            if ($count >= $maxAttempts) {
                $error = "Příliš mnoho neúspěšných pokusů (5/5). Přístup byl zablokován na 30 minut.";
            } else {
                $remaining = $maxAttempts - $count;
                $error = "Nesprávné heslo. Zbývá pokusů: {$remaining}.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obsidian Viewer - Přihlášení</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            background-color: #000000;
            color: #dcddde;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            background-color: #111111;
            border: 1px solid #262626;
            border-radius: 12px;
            padding: 32px 28px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .logo {
            width: 48px;
            height: 48px;
            margin-bottom: 16px;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 24px;
            letter-spacing: -0.02em;
        }
        form {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 18px;
        }
        input[type="password"] {
            background-color: #181818;
            border: 1px solid #333333;
            border-radius: 8px;
            color: #ffffff;
            font-size: 1rem;
            padding: 12px 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 100%;
        }
        input[type="password"]:focus {
            border-color: #7f6df2;
            box-shadow: 0 0 0 2px rgba(127, 109, 242, 0.25);
        }
        small {
            color: #8e8e93;
            font-size: 0.82rem;
            margin-top: 6px;
            margin-left: 2px;
            display: block;
        }
        button[type="submit"] {
            background-color: #7f6df2;
            border: none;
            border-radius: 8px;
            color: #ffffff;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 12px;
            transition: background-color 0.2s;
            width: 100%;
            margin-top: 4px;
        }
        button[type="submit"]:hover {
            background-color: #6b59e6;
        }
        button[type="submit"]:active {
            background-color: #5846d1;
        }
        .error-message {
            background-color: rgba(235, 87, 87, 0.12);
            border: 1px solid rgba(235, 87, 87, 0.4);
            border-radius: 8px;
            color: #eb5757;
            font-size: 0.88rem;
            padding: 12px;
            margin-bottom: 18px;
            width: 100%;
            line-height: 1.4;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <svg class="logo" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M38.5 8.5L78.5 28.5L91.5 68.5L48.5 91.5L8.5 63.5L18.5 28.5L38.5 8.5Z" fill="#7F6DF2" fill-opacity="0.2" stroke="#7F6DF2" stroke-width="6" stroke-linejoin="round"/>
            <path d="M38.5 8.5L48.5 91.5" stroke="#7F6DF2" stroke-width="4"/>
            <path d="M18.5 28.5L78.5 28.5" stroke="#7F6DF2" stroke-width="4"/>
            <path d="M8.5 63.5L91.5 68.5" stroke="#7F6DF2" stroke-width="4"/>
        </svg>
        <h1>Obsidian Vault</h1>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="input-group">
                <input type="password" name="password" placeholder="Heslo" required autofocus autocomplete="current-password">
                <small>heslo do mého telefonu</small>
            </div>
            <button type="submit">Přihlásit se</button>
        </form>
    </div>
</body>
</html>
