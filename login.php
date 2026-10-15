<?php
// login.php - Secure user authentication against SQLite database with rate limiting

require_once __DIR__ . '/auth.php';

// Handle logout
if (isset($_GET['logout'])) {
    logoutUser();
    header("Location: login.php");
    exit;
}

// Redirect if already logged in
$currentUser = getCurrentUser();
if ($currentUser && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$error = '';
$ip = getClientIp();
$attemptsFile = __DIR__ . '/data/ip_attempts.json';
$maxAttempts = 5;
$lockoutDuration = 1800; // 30 minutes in seconds

// Ensure data directory exists
if (!is_dir(__DIR__ . '/data')) {
    @mkdir(__DIR__ . '/data', 0775, true);
}

// Load attempts data
$attemptsData = [];
if (file_exists($attemptsFile)) {
    $json = @file_get_contents($attemptsFile);
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
            @file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));
        }
    }
}

// Process login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($error)) {
        // Already blocked by rate limiter
    } elseif (empty($username) || empty($password)) {
        $error = "Vyplňte prosím uživatelské jméno i heslo.";
    } else {
        try {
            $pdo = getDb();
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, is_admin, session_version 
                FROM users 
                WHERE username = :username COLLATE NOCASE
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Successful login: reset attempts
                if (isset($attemptsData[$ip])) {
                    unset($attemptsData[$ip]);
                    @file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));
                }

                // Prevent session fixation
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = (bool)$user['is_admin'];
                $_SESSION['session_version'] = (int)$user['session_version'];
                $_SESSION['logged_in'] = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

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
                @file_put_contents($attemptsFile, json_encode($attemptsData, JSON_PRETTY_PRINT));

                $count = $attemptsData[$ip]['attempts'];
                if ($count >= $maxAttempts) {
                    $error = "Příliš mnoho neúspěšných pokusů (5/5). Přístup byl zablokován na 30 minut.";
                } else {
                    $remaining = $maxAttempts - $count;
                    $error = "Nesprávné uživatelské jméno nebo heslo. Zbývá pokusů: {$remaining}.";
                }

                // Audit log failed login attempt
                auditLog('system', 'DENIED', "Failed login attempt for user: {$username}", $username, $ip);
            }
        } catch (\Throwable $e) {
            $error = "Chyba databáze při přihlašování. Zkontrolujte oprávnění souboru databáze.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obsidian Web - Přihlášení</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            background-color: #000000;
            color: #dcddde;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            background-color: #0d0d0e;
            border: 1px solid #1f1f24;
            border-radius: 12px;
            padding: 36px 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.7);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .logo {
            width: 52px;
            height: 52px;
            margin-bottom: 16px;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }
        .subtitle {
            color: #8e8e93;
            font-size: 0.85rem;
            margin-bottom: 24px;
        }
        form {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .input-group label {
            font-size: 0.82rem;
            color: #8e8e93;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"] {
            background-color: #141416;
            border: 1px solid #2a2a30;
            border-radius: 8px;
            color: #ffffff;
            font-size: 0.95rem;
            padding: 12px 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 100%;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #7f6df2;
            box-shadow: 0 0 0 2px rgba(127, 109, 242, 0.25);
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
            margin-top: 6px;
            box-shadow: 0 4px 12px rgba(127, 109, 242, 0.3);
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
            margin-bottom: 20px;
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
        <h1>Obsidian Web</h1>
        <p class="subtitle">Přihlaste se ke svému trezoru</p>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="input-group">
                <label for="username">Uživatelské jméno</label>
                <input type="text" id="username" name="username" placeholder="Uživatelské jméno" required autofocus autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="input-group">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password" placeholder="Heslo" required autocomplete="current-password">
            </div>
            <button type="submit">Přihlásit se</button>
        </form>
    </div>
</body>
</html>
