<?php

declare(strict_types=1);

// Put this file into backend/ and run `php refactor_inventory_receive.php --apply`.
$testsDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature';
$apply = in_array('--apply', $argv, true);

if (!is_dir($testsDirectory)) {
    fwrite(STDERR, "Not found: {$testsDirectory}\n");
    exit(1);
}

function receiveCallArguments(string $source, int $open): array
{
    $tokens = token_get_all('<?php ' . substr($source, $open));
    $cursor = $open;
    $round = 0;
    $square = 0;
    $curly = 0;
    $commas = [];
    $closing = null;

    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_OPEN_TAG) {
            continue;
        }

        $value = is_array($token) ? $token[1] : $token;

        if ($value === '(') {
            ++$round;
        } elseif ($value === ')') {
            --$round;
            if ($round === 0) {
                $closing = $cursor;
                break;
            }
        } elseif ($value === '[') {
            ++$square;
        } elseif ($value === ']') {
            --$square;
        } elseif ($value === '{') {
            ++$curly;
        } elseif ($value === '}') {
            --$curly;
        } elseif ($value === ',' && $round === 1 && $square === 0 && $curly === 0) {
            $commas[] = $cursor;
        }

        $cursor += strlen($value);
    }

    if ($closing === null) {
        throw new RuntimeException('Unable to locate closing bracket of receive().');
    }

    $arguments = [];
    $from = $open + 1;

    foreach ($commas as $position) {
        $arguments[] = trim(substr($source, $from, $position - $from));
        $from = $position + 1;
    }

    $lastArgument = trim(substr($source, $from, $closing - $from));
    if ($lastArgument !== '') {
        $arguments[] = $lastArgument;
    }

    if (count($arguments) < 4 || count($arguments) > 5 || in_array('', $arguments, true)) {
        throw new RuntimeException('Unexpected number of receive() arguments: ' . count($arguments));
    }

    return [$closing, $arguments];
}

$pattern = '/(?:\$this->inventory|\$inventory)->receive\s*\(/';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testsDirectory, FilesystemIterator::SKIP_DOTS)
);
$changes = [];
$total = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);
    preg_match_all($pattern, $original, $matches, PREG_OFFSET_CAPTURE);

    if (!$matches[0]) {
        continue;
    }

    $updated = $original;
    foreach (array_reverse($matches[0]) as [$match, $start]) {
        $open = $start + strlen($match) - 1;
        [$closing, $arguments] = receiveCallArguments($original, $open);
        array_splice($arguments, 4, 0, ['(string) \\Illuminate\\Support\\Str::uuid()']);

        $rawArgs = substr($original, $open + 1, $closing - $open - 1);
        $multiLine = str_contains($rawArgs, "\n");
        $receiver = preg_replace('/->receive\s*\($/', '', $match);

        if ($multiLine) {
            preg_match('/\n([ \t]*)\S/', $rawArgs, $indentMatch);
            preg_match('/\n([ \t]*)$/', $rawArgs, $closingMatch);
            $indent = $indentMatch[1] ?? '            ';
            $closingIndent = $closingMatch[1] ?? '';
            $replacement = $receiver . '->receiveOnce(' . "\n" . $indent
                . implode(",\n" . $indent, $arguments) . "\n" . $closingIndent . ')';
        } else {
            $replacement = $receiver . '->receiveOnce(' . implode(', ', $arguments) . ')';
        }

        $updated = substr_replace($updated, $replacement, $start, $closing - $start + 1);
        ++$total;
    }

    $changes[$path] = $updated;
    printf("%s: %d calls\n", basename($path), count($matches[0]));
}

printf("Total: %d calls in %d test files\n", $total, count($changes));

if (!$apply) {
    echo "DRY RUN. To modify test files: php refactor_inventory_receive.php --apply\n";
    exit(0);
}

if ($total === 0) {
    fwrite(STDERR, "No calls to change.\n");
    exit(1);
}

foreach ($changes as $path => $_) {
    if (is_file($path . '.receive-backup')) {
        throw new RuntimeException("Backup already exists: {$path}.receive-backup");
    }
}

foreach ($changes as $path => $updated) {
    if (!copy($path, $path . '.receive-backup')) {
        throw new RuntimeException("Cannot back up {$path}");
    }
    if (file_put_contents($path, $updated) === false) {
        throw new RuntimeException("Cannot write {$path}");
    }
}

echo "Updated test calls. Now remove the old public receive() method from InventoryService.php.\n";
