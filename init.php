<?php
header('Location: setup.php');
exit;

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'student-req';

$messages = [];
$errors   = [];
try {
    $pdo = new PDO("mysql:host={$DB_HOST};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $messages[] = "Connected to MySQL at {$DB_HOST}.";

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $messages[] = "Database `{$DB_NAME}` ready.";
    $pdo->exec("USE `{$DB_NAME}`");

    $candidates = [__DIR__ . '/databse.sql', __DIR__ . '/database.sql'];
    $schemaFile = null;
    foreach ($candidates as $f) {
        if (is_file($f)) { $schemaFile = $f; break; }
    }
    if (!$schemaFile) {
        throw new RuntimeException('Schema file not found (expected databse.sql or database.sql).');
    }
    $messages[] = "Found schema: " . basename($schemaFile);

    $sql = file_get_contents($schemaFile);
    $sql = preg_replace('/^\s*DROP\s+DATABASE\s+IF\s+EXISTS\s+\S+\s*;/mi', '', $sql);
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\s+.*?;/mis',                    '', $sql);
    $sql = preg_replace('/^\s*USE\s+\S+\s*;/mi',                                '', $sql);

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    $statements = array_values(array_filter(array_map('trim', $statements), function ($s) {
        if ($s === '') return false;
        $nonComment = preg_replace('/^\s*--.*$/m', '', $s);
        return trim($nonComment) !== '';
    }));

    $ok = 0; $skipped = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($code, [1050, 1062], true)) { $skipped++; continue; }
            throw $e;
        }
    }
    $messages[] = "Schema applied: {$ok} statement(s) executed, {$skipped} skipped (already present).";

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $messages[] = "Users table now contains {$count} row(s).";

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$ok = empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Setup · SRVP</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/glass.css">
<style>
  body { display: grid; place-items: center; min-height: 100vh; padding: 24px; }
  .setup-card { max-width: 640px; width: 100%; padding: 32px; }
  .setup-list { list-style: none; padding: 0; margin: 18px 0; }
  .setup-list li { padding: 8px 0 8px 26px; position: relative; color: rgba(238,240,255,.85); }
  .setup-list li::before { content: '✓'; position: absolute; left: 0; color: #8db99b; font-weight: 700; }
  .setup-list.error li::before { content: '✕'; color: #d4766a; }
  .setup-actions { display: flex; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
</style>
</head>
<body>
<main class="glass setup-card clay panel-clay">
  <h1><?= $ok ? 'Setup complete' : 'Setup failed' ?></h1>
  <p class="muted"><?= $ok
      ? 'Your SRVP database is ready. You can safely delete init.php now.'
      : 'Fix the issue below and refresh to retry.' ?></p>

  <?php if ($messages): ?>
    <ul class="setup-list">
      <?php foreach ($messages as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($errors): ?>
    <ul class="setup-list error">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="setup-actions">
    <?php if ($ok): ?>
      <a class="btn btn-primary" href="index.php">Open the portal →</a>
      <a class="btn btn-ghost" href="login.php">Sign in</a>
    <?php else: ?>
      <a class="btn btn-ghost" href="init.php">Retry</a>
    <?php endif; ?>
  </div>
</main>
</body>
</html>