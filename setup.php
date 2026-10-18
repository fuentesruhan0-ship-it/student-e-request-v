<?php
/**
 * One-click installer — creates the database, tables, SPs, triggers, views
 * and seed data from database.sql.
 *
 * Re-running this file is IDEMPOTENT: every SQL statement uses
 * CREATE TABLE IF NOT EXISTS / INSERT IGNORE / DROP ... IF EXISTS,
 * so it is safe to open this page again to ensure schema is up to date.
 *
 * ###########################################################################
 * # IMPORTANT — DELETE THIS FILE AFTER FIRST USE ON A PUBLIC SERVER.       #
 * #                                                                         #
 * # It exposes database status and will re-seed defaults if left around.    #
 * ###########################################################################
 */

declare(strict_types=1);

$self    = basename(__FILE__);
$rootDir = __DIR__;
$sqlFile = $rootDir . DIRECTORY_SEPARATOR . 'database.sql';

require_once $rootDir . '/includes/functions.php';

$done   = false;
$errors = [];
$steps  = [];

// -----------------------------------------------------------------------------
// 1. Connect WITHOUT selecting a schema yet, so we can CREATE DATABASE.
// -----------------------------------------------------------------------------
$pdoNoDb = null;
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $pdoNoDb = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $steps[] = [true, 'Connected to MySQL server at ' . DB_HOST . ':' . DB_PORT . ' as ' . DB_USER];
} catch (PDOException $e) {
    $errors[] = 'Could not connect to MySQL: ' . $e->getMessage()
        . ' — please verify DB_HOST / DB_PORT / DB_USER / DB_PASS in config/database.php'
        . ' (or override them via a .env.local file).';
}

// -----------------------------------------------------------------------------
// 2. Create the database if it doesn't exist.
// -----------------------------------------------------------------------------
if ($pdoNoDb && !$errors) {
    try {
        $pdoNoDb->exec(
            "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`"
            . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $steps[] = [true, 'Database `' . DB_NAME . '` ready (created if missing).'];
    } catch (PDOException $e) {
        $errors[] = 'Could not create database: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// 3. Re-connect with DB selected, then run database.sql split by DELIMITER.
// -----------------------------------------------------------------------------
$db = null;
if (!$errors) {
    try {
        $db = db();
        $steps[] = [true, 'Reconnected using `' . DB_NAME . '`.'];
    } catch (Throwable $e) {
        $errors[] = 'DB selected connection failed: ' . $e->getMessage();
    }
}

if ($db && !$errors) {
    if (!is_file($sqlFile) || !is_readable($sqlFile)) {
        $errors[] = "Cannot read database.sql — expected at: $sqlFile";
    } else {
        $raw = file_get_contents($sqlFile);
        if ($raw === false) {
            $errors[] = 'Failed to read database.sql contents.';
        } else {
            // Run through the PDO connection, honouring DELIMITER // ... // blocks.
            $queries = split_sql_with_delimiters($raw);
            $okCount = 0;
            foreach ($queries as $i => $q) {
                $q = trim($q);
                if ($q === '') {
                    continue;
                }
                try {
                    $db->exec($q);
                    $okCount++;
                } catch (PDOException $e) {
                    // Permissive: some MySQL versions warn on DROP IF EXISTS on
                    // non-existent objects. We still surface the message as a
                    // notice rather than aborting.
                    $steps[] = [false, 'Query #' . ($i + 1) . ' note: ' . $e->getMessage()];
                }
            }
            $steps[] = [true, "Executed schema script ($okCount statements applied)."];

            // Quick sanity checks.
            $tables = ['students','cashiers','registrars','services','requests','payments','receipts','audit_logs'];
            foreach ($tables as $t) {
                $exists = $db->query("SHOW TABLES LIKE '$t'")->rowCount() > 0;
                $steps[] = [$exists, ($exists ? '✓' : '✗') . " table `$t`"];
            }
            foreach (['registrar.admin@gmail.com','cashier.admin@gmail.com'] as $email) {
                $stmt = $db->prepare('SELECT 1 FROM registrars WHERE email = ? UNION ALL SELECT 1 FROM cashiers WHERE email = ? LIMIT 1');
                $stmt->execute([$email, $email]);
                $has = $stmt->fetch() !== false;
                $steps[] = [$has, ($has ? '✓' : '✗') . " seed user '$email'"];
            }
            $svc = (int) $db->query('SELECT COUNT(*) FROM services')->fetchColumn();
            $steps[] = [$svc > 0, "Services seeded ($svc services)."];

            $done = true;
        }
    }
}

// -----------------------------------------------------------------------------
// 4. Helpers
// -----------------------------------------------------------------------------
function split_sql_with_delimiters(string $sql): array
{
    $out      = [];
    $buffer   = '';
    $inStr    = null;
    $escaped  = false;
    $len      = strlen($sql);
    $delim    = ';';
    $i        = 0;

    while ($i < $len) {
        $ch = $sql[$i];

        // DELIMITER change at line start (MySQL CLI style) — consume whole line.
        if (trim($buffer) === '' && stripos(substr($sql, $i, 9), 'DELIMITER') === 0) {
            $lineEnd = strpos($sql, "\n", $i);
            if ($lineEnd === false) $lineEnd = $len;
            $line = trim(substr($sql, $i, $lineEnd - $i));
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[1])) {
                $delim = $parts[1];
            }
            $buffer = '';
            $i = $lineEnd + 1;
            continue;
        }

        if ($escaped) {
            $buffer .= $ch;
            $escaped = false;
            $i++;
            continue;
        }
        if ($ch === '\\') {
            $buffer .= $ch;
            $escaped = true;
            $i++;
            continue;
        }
        if ($inStr !== null) {
            $buffer .= $ch;
            if ($ch === $inStr) $inStr = null;
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inStr = $ch;
            $buffer .= $ch;
            $i++;
            continue;
        }
        // Comment -- (line)
        if ($ch === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
            $lineEnd = strpos($sql, "\n", $i);
            if ($lineEnd === false) $lineEnd = $len;
            $i = $lineEnd + 1;
            continue;
        }
        // Comment # (line)
        if ($ch === '#') {
            $lineEnd = strpos($sql, "\n", $i);
            if ($lineEnd === false) $lineEnd = $len;
            $i = $lineEnd + 1;
            continue;
        }
        // Comment /* block */
        if ($ch === '/' && isset($sql[$i + 1]) && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) { $i = $len; continue; }
            $i = $end + 2;
            continue;
        }

        // Delimiter match
        if (substr($sql, $i, strlen($delim)) === $delim) {
            $out[] = $buffer;
            $buffer = '';
            $i += strlen($delim);
            continue;
        }

        $buffer .= $ch;
        $i++;
    }
    if (trim($buffer) !== '') $out[] = $buffer;
    return $out;
}

// -----------------------------------------------------------------------------
// 5. Output
// -----------------------------------------------------------------------------
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup · SRVP</title>
<style>
:root{--bg:#0b0f19;--fg:#e6e9f2;--muted:#9aa4b8;--ok:#22c55e;--bad:#ef4444;--info:#60a5fa;--glass:rgba(255,255,255,.06);--stroke:rgba(255,255,255,.12)}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,sans-serif;background:var(--bg);color:var(--fg);padding:48px 20px;line-height:1.5}
.wrap{max-width:720px;margin:0 auto}.card{background:var(--glass);border:1px solid var(--stroke);border-radius:16px;padding:28px 32px;backdrop-filter:blur(14px)}
h1{margin:0 0 8px;font-size:28px}h2{margin:28px 0 12px;font-size:18px;color:var(--muted);font-weight:600;letter-spacing:.05em;text-transform:uppercase}
p{color:var(--muted);margin:0 0 20px}.ok{color:var(--ok)}.bad{color:var(--bad)}.info{color:var(--info)}
ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}li{padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid var(--stroke);font-size:14px}
code{background:rgba(255,255,255,.08);padding:2px 8px;border-radius:6px;font-size:12.5px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
.box{padding:16px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--stroke)}
.box b{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
.box span{font-size:15px}
.cta{margin-top:24px;display:flex;gap:12px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;border:1px solid var(--stroke);color:var(--fg);background:rgba(255,255,255,.06)}
.btn:hover{background:rgba(255,255,255,.12)}.btn.primary{background:#6366f1;border-color:#6366f1;color:#fff}.btn.primary:hover{background:#4f46e5;border-color:#4f46e5}
.warn{margin-top:20px;padding:16px 18px;border-radius:12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:13.5px}
.warn b{color:#fecaca}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1><?= $done ? '✅ Setup complete' : '🛠️ SRVP installer' ?></h1>
    <p>One-click install of the Student Request &amp; Payment Portal database schema, stored procedures, triggers, views and seed data.</p>

    <?php if ($errors): ?>
      <h2>Errors</h2>
      <ul>
        <?php foreach ($errors as $err): ?>
          <li class="bad"><span class="bad">✗</span> <?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Progress</h2>
    <ul>
      <?php foreach ($steps as [$ok, $msg]): ?>
        <li class="<?= $ok ? 'ok' : 'info' ?>"><?= $ok ? '✓' : 'ℹ' ?> <?= htmlspecialchars($msg) ?></li>
      <?php endforeach; ?>
    </ul>

    <?php if ($done): ?>
    <h2>Default demo logins</h2>
    <div class="grid">
      <div class="box"><b>Registrar</b><span><code>REG-00001</code> / <code>registrar.admin@gmail.com</code></span><br><span class="muted">pass <code>Admin@1234</code></span></div>
      <div class="box"><b>Cashier</b><span><code>CSH-00001</code> / <code>cashier.admin@gmail.com</code></span><br><span class="muted">pass <code>Admin@1234</code></span></div>
    </div>
    <p style="margin-top:16px;font-size:13.5px">Students can register directly using the format <code>YYYY-NNNNN</code> (e.g. <code>2024-10001</code>) and any <code>@gmail.com</code> address.</p>

    <div class="warn">
      <b>⚠️ Production notice:</b> Delete this file (<code><?= htmlspecialchars($self) ?></code>) before exposing the site.
      Then change the default passwords above, ideally by signing in to each admin account and using the "Change password" page.
    </div>
    <?php endif; ?>

    <div class="cta">
      <a class="btn primary" href="<?= url('login.php') ?>">Open Sign in →</a>
      <a class="btn" href="<?= url('index.php') ?>">Home page</a>
      <?php if ($done): ?>
        <a class="btn" href="<?= htmlspecialchars($self) ?>">Re-run installer</a>
      <?php else: ?>
        <a class="btn" href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? $self) ?>">Try again</a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
