<?php

declare(strict_types=1);

final class RuleRunner
{
    private string $rulesDir;
    private string $pythonBin;
    private int $timeoutSeconds;

    public function __construct(string $rulesDir, string $pythonBin = 'python3', int $timeoutSeconds = 10)
    {
        $this->rulesDir = rtrim($rulesDir, '/');
        $this->pythonBin = $pythonBin;
        $this->timeoutSeconds = max(1, $timeoutSeconds);
    }

    /**
     * @return list<array{id: string, name: string, description: string, category: string}>
     */
    public function listRules(): array
    {
        $root = $this->rulesRoot();
        if ($root === null) {
            return [];
        }

        $entries = scandir($root);
        if ($entries === false) {
            return [];
        }

        $rules = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $meta = $this->readRule($entry);
            if ($meta !== null) {
                $rules[] = [
                    'id' => $meta['id'],
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'category' => $meta['category'],
                ];
            }
        }

        usort($rules, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $rules;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: true, result: array{rule: string, success: bool, violations: list<array{employee: string, date: string, message: string, severity: string}>}}|array{ok: false, error: string}
     */
    public function run(string $ruleId, array $payload): array
    {
        $meta = $this->readRule($ruleId);
        if ($meta === null) {
            return ['ok' => false, 'error' => 'Die Regel ist nicht freigegeben.'];
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ok' => false, 'error' => 'Der Dienstplan konnte nicht an die Regel übergeben werden.'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$this->pythonBin, $meta['script']],
            $descriptors,
            $pipes,
            $meta['dir'],
            $this->environment()
        );

        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Python konnte nicht gestartet werden.'];
        }

        fwrite($pipes[0], $json);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        $exitCode = 1;
        $maxBytes = 1024 * 1024;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= $this->readPipe($pipes[1]);
            $stderr .= $this->readPipe($pipes[2]);

            if (strlen($stdout) > $maxBytes || strlen($stderr) > $maxBytes) {
                $this->stopProcess($process);
                $timedOut = true;
                break;
            }

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) > $this->timeoutSeconds) {
                $this->stopProcess($process);
                $timedOut = true;
                break;
            }

            usleep(50000);
        }

        $stdout .= $this->readPipe($pipes[1]);
        $stderr .= $this->readPipe($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            return ['ok' => false, 'error' => 'Die Regel hat das Zeitlimit überschritten.'];
        }

        if ($exitCode !== 0) {
            $detail = trim($stderr);
            if ($detail === '') {
                $detail = 'Python hat die Regel mit einem Fehler beendet.';
            }

            return ['ok' => false, 'error' => $this->shorten($detail)];
        }

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ok' => false, 'error' => 'Die Regel hat kein gültiges JSON geliefert.'];
        }

        $validated = $this->validateResult($ruleId, $decoded);
        if ($validated === null) {
            return ['ok' => false, 'error' => 'Die Regel hat ein unerwartetes Ergebnisformat geliefert.'];
        }

        return ['ok' => true, 'result' => $validated];
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }

        $clean = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $clean[$key] = (string) $value;
            }
        }

        $clean['PYTHONDONTWRITEBYTECODE'] = '1';
        $clean['PYTHONUNBUFFERED'] = '1';

        return $clean;
    }

    /**
     * @param resource $pipe
     */
    private function readPipe($pipe): string
    {
        $chunk = stream_get_contents($pipe);

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * @param resource $process
     */
    private function stopProcess($process): void
    {
        proc_terminate($process, 15);
        usleep(100000);
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
    }

    private function shorten(string $text): string
    {
        if (strlen($text) <= 400) {
            return $text;
        }

        return substr($text, 0, 400) . '…';
    }

    /**
     * @return array{id: string, name: string, description: string, category: string, dir: string, script: string}|null
     */
    private function readRule(string $id): ?array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            return null;
        }

        $root = $this->rulesRoot();
        if ($root === null) {
            return null;
        }

        $dir = realpath($root . '/' . $id);
        if ($dir === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $metaFile = $dir . '/rule.json';
        $script = $dir . '/rule.py';
        if (!is_file($metaFile) || !is_file($script)) {
            return null;
        }

        try {
            $raw = file_get_contents($metaFile);
            if ($raw === false) {
                return null;
            }
            $meta = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($meta) || ($meta['id'] ?? '') !== $id) {
            return null;
        }

        foreach (['name', 'description', 'category'] as $key) {
            if (!isset($meta[$key]) || !is_string($meta[$key]) || $meta[$key] === '') {
                return null;
            }
        }

        $scriptReal = realpath($script);
        if ($scriptReal === false || !str_starts_with($scriptReal, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $meta['name'],
            'description' => $meta['description'],
            'category' => $meta['category'],
            'dir' => $dir,
            'script' => $scriptReal,
        ];
    }

    private function rulesRoot(): ?string
    {
        $root = realpath($this->rulesDir);
        if ($root === false || !is_dir($root)) {
            return null;
        }

        return $root;
    }

    /**
     * @param mixed $decoded
     * @return array{rule: string, success: bool, violations: list<array{employee: string, date: string, message: string, severity: string}>}|null
     */
    private function validateResult(string $ruleId, mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }
        if (($decoded['rule'] ?? null) !== $ruleId || !is_bool($decoded['success'] ?? null)) {
            return null;
        }
        if (!isset($decoded['violations']) || !array_is_list($decoded['violations'])) {
            return null;
        }

        $violations = [];
        foreach ($decoded['violations'] as $violation) {
            if (!is_array($violation)) {
                return null;
            }
            foreach (['employee', 'date', 'message'] as $key) {
                if (!isset($violation[$key]) || !is_string($violation[$key])) {
                    return null;
                }
            }
            $severity = $violation['severity'] ?? '';
            if ($severity !== 'error' && $severity !== 'warning') {
                return null;
            }
            $violations[] = [
                'employee' => $violation['employee'],
                'date' => $violation['date'],
                'message' => $violation['message'],
                'severity' => $severity,
            ];
        }

        return [
            'rule' => $ruleId,
            'success' => $decoded['success'],
            'violations' => $violations,
        ];
    }
}
