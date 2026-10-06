<?php

declare(strict_types=1);

require_once '/var/www/php/src/RuleRunner.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function envString(string $name, string $default): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        return $default;
    }

    return $value;
}

/**
 * @return array{ok: true, data: array<string, mixed>, file: string}|array{ok: false, error: string, file: string}
 */
function loadPlan(string $dataDir, string $planFile): array
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $planFile)) {
        return ['ok' => false, 'error' => 'Der Name der Plandatei ist ungültig.', 'file' => $planFile];
    }

    $root = realpath($dataDir);
    $path = realpath($dataDir . '/' . $planFile);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return ['ok' => false, 'error' => 'Die Test-Plandatei wurde nicht gefunden.', 'file' => $planFile];
    }

    try {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Die Test-Plandatei konnte nicht gelesen werden.', 'file' => $planFile];
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'error' => 'Die Test-Plandatei enthält kein gültiges JSON.', 'file' => $planFile];
    }

    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Die Test-Plandatei hat das falsche Format.', 'file' => $planFile];
    }

    return ['ok' => true, 'data' => $data, 'file' => $planFile];
}

/**
 * @param array{ok: bool, error?: string, result?: array{rule: string, success: bool, violations: list<array{employee: string, date: string, message: string, severity: string}>}} $run
 */
function classifyRun(array $run): string
{
    if (!$run['ok'] || !isset($run['result'])) {
        return 'technical';
    }

    $hasError = !$run['result']['success'];
    $hasWarning = false;
    foreach ($run['result']['violations'] as $violation) {
        if ($violation['severity'] === 'error') {
            $hasError = true;
        }
        if ($violation['severity'] === 'warning') {
            $hasWarning = true;
        }
    }

    if ($hasError) {
        return 'error';
    }
    if ($hasWarning) {
        return 'warning';
    }

    return 'ok';
}

$runner = new RuleRunner(
    envString('RULES_DIR', '/var/www/rules'),
    envString('PYTHON_BIN', 'python3'),
    (int) envString('RULE_TIMEOUT', '10')
);

$rules = $runner->listRules();
$rulesById = [];
foreach ($rules as $rule) {
    $rulesById[$rule['id']] = $rule;
}

$plan = loadPlan(envString('DATA_DIR', '/var/www/data'), envString('PLAN_FILE', 'dienstplan_test.json'));
$zeitraum = '';
if ($plan['ok'] && isset($plan['data']['zeitraum']) && is_string($plan['data']['zeitraum'])) {
    $zeitraum = $plan['data']['zeitraum'];
}

$selected = null;
$formError = $plan['ok'] ? '' : $plan['error'];
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['rules'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }

    $selected = [];
    foreach ($posted as $id) {
        if (is_string($id) && isset($rulesById[$id])) {
            $selected[] = $id;
        }
    }

    if (!$plan['ok']) {
        $formError = $plan['error'];
    } elseif ($selected === []) {
        $formError = 'Bitte mindestens eine Prüfregel auswählen.';
    } else {
        foreach ($selected as $id) {
            $results[] = [
                'id' => $id,
                'name' => $rulesById[$id]['name'],
                'run' => $runner->run($id, $plan['data']),
            ];
        }
    }
}

$summary = ['ok' => 0, 'warning' => 0, 'error' => 0, 'technical' => 0];
foreach ($results as $result) {
    $summary[classifyRun($result['run'])]++;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DPLChk2 - Dienstplan-Prüfer</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f6f9;
            color: #333;
            max-width: 860px;
            margin: 40px auto;
            padding: 20px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }

        h1 {
            color: #2c3e50;
            margin-top: 0;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 12px;
        }

        h2 {
            color: #2c3e50;
            font-size: 1.1rem;
            margin: 0 0 14px;
        }

        .subtitle, .meta, .rule-description, .footer {
            color: #666;
        }

        .meta {
            margin: 0 0 18px;
            font-size: 0.95rem;
        }

        .rule {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .rule:last-of-type {
            border-bottom: none;
        }

        .rule input {
            margin-top: 4px;
        }

        .rule-description, .category {
            display: block;
            margin-top: 4px;
            font-size: 0.9rem;
        }

        .category {
            color: #888;
        }

        button {
            margin-top: 18px;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 1rem;
            cursor: pointer;
        }

        button:hover {
            background: #1a252f;
        }

        .message {
            background: #fff3cd;
            color: #856404;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .count {
            border-radius: 8px;
            padding: 14px;
        }

        .count strong {
            display: block;
            font-size: 1.4rem;
        }

        .count-ok { background: #d4edda; color: #155724; }
        .count-warning { background: #fff3cd; color: #856404; }
        .count-error { background: #f8d7da; color: #721c24; }

        .result {
            padding: 14px 0;
            border-top: 1px solid #eee;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-ok { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-error, .badge-technical { background: #f8d7da; color: #721c24; }

        ul {
            margin: 10px 0 0;
            padding-left: 18px;
        }

        li {
            margin: 6px 0;
        }

        .footer {
            font-size: 0.85rem;
            text-align: right;
        }

        .footer a {
            color: #2c3e50;
        }

        @media (max-width: 640px) {
            .summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="card">
    <h1>Dienstplan-Prüfer</h1>
    <p class="subtitle">Die Fachprüfung läuft in den Python-Regeln. Diese Seite startet sie nur und zeigt das Ergebnis.</p>

    <h2>Dienstplan</h2>
    <p class="meta">
        <?= h($plan['file']) ?>
        <?php if ($zeitraum !== ''): ?>
            <br><?= h($zeitraum) ?>
        <?php endif; ?>
    </p>

    <?php if ($formError !== ''): ?>
        <div class="message"><?= h($formError) ?></div>
    <?php endif; ?>

    <form method="post" action="index.php">
        <h2>Prüfregeln</h2>

        <?php if ($rules === []): ?>
            <p class="meta">Es wurden keine Prüfregeln gefunden.</p>
        <?php endif; ?>

        <?php foreach ($rules as $rule): ?>
            <?php $checked = $selected === null || in_array($rule['id'], $selected, true); ?>
            <label class="rule">
                <input type="checkbox" name="rules[]" value="<?= h($rule['id']) ?>" <?= $checked ? 'checked' : '' ?>>
                <span>
                    <strong><?= h($rule['name']) ?></strong>
                    <span class="rule-description"><?= h($rule['description']) ?></span>
                    <span class="category"><?= h($rule['category']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>

        <button type="submit" <?= $rules === [] || !$plan['ok'] ? 'disabled' : '' ?>>Prüfung starten</button>
    </form>
</div>

<?php if ($results !== []): ?>
<div class="card">
    <h2>Ergebnis</h2>

    <div class="summary">
        <div class="count count-ok">
            <strong><?= $summary['ok'] ?></strong>
            ohne Beanstandung
        </div>
        <div class="count count-warning">
            <strong><?= $summary['warning'] ?></strong>
            mit Hinweisen
        </div>
        <div class="count count-error">
            <strong><?= $summary['error'] ?></strong>
            verletzt
        </div>
    </div>

    <?php if ($summary['technical'] > 0): ?>
        <div class="message"><?= $summary['technical'] ?> Regel(n) konnten nicht ausgeführt werden.</div>
    <?php endif; ?>

    <?php foreach ($results as $result): ?>
        <?php
        $kind = classifyRun($result['run']);
        $label = [
            'ok' => 'ohne Beanstandung',
            'warning' => 'Hinweis',
            'error' => 'verletzt',
            'technical' => 'Ausführungsfehler',
        ][$kind];
        ?>
        <div class="result">
            <strong><?= h($result['name']) ?></strong>
            <span class="badge badge-<?= h($kind) ?>"><?= h($label) ?></span>

            <?php if ($kind === 'technical'): ?>
                <p class="meta"><?= h($result['run']['error'] ?? 'Unbekannter Fehler') ?></p>
            <?php elseif ($result['run']['result']['violations'] !== []): ?>
                <ul>
                    <?php foreach ($result['run']['result']['violations'] as $violation): ?>
                        <li>
                            <span class="badge badge-<?= h($violation['severity']) ?>">
                                <?= $violation['severity'] === 'warning' ? 'Hinweis' : 'Verstoß' ?>
                            </span>
                            <?= h($violation['employee']) ?>,
                            <?= h($violation['date']) ?>:
                            <?= h($violation['message']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="footer"><a href="status.php">Dienst-Status</a></p>

</body>
</html>
