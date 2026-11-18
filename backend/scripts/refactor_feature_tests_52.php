<?php

declare(strict_types=1);

/**
 * BOARDRIDERS 52.1
 *
 * Safe refactoring of CheckoutCodTtnInvestigationCaseTest:
 * moves test_* methods into thematic PHP traits.
 *
 * Default: preview only.
 * --apply: create traits and update original test class.
 */

$root = getcwd();

$sourceFile = $root
    . '/tests/Feature/CheckoutCodTtnInvestigationCaseTest.php';

$outDir = $root . '/tests/Feature/Concerns';

$apply = in_array('--apply', $argv, true);

if (!is_file($sourceFile)) {
    fwrite(STDERR, "Feature test file not found\n");
    exit(1);
}

$source = file_get_contents($sourceFile);

if (!is_string($source) || $source === '') {
    fwrite(STDERR, "Cannot read Feature test file\n");
    exit(1);
}

if (!preg_match(
    '/\bfinal\s+class\s+CheckoutCodTtnInvestigationCaseTest\b/',
    $source,
    $decl,
    PREG_OFFSET_CAPTURE
)) {
    fwrite(STDERR, "Expected test class not found\n");
    exit(1);
}

$header = substr($source, 0, $decl[0][1]);

if (substr_count($header, 'namespace Tests\Feature;') !== 1) {
    fwrite(STDERR, "Unexpected test namespace\n");
    exit(1);
}

$traitHeader = str_replace(
    'namespace Tests\Feature;',
    'namespace Tests\Feature\Concerns;',
    $header
);

if (str_contains(
    $source,
    'use \Tests\Feature\Concerns\Cod52'
)) {
    fwrite(STDERR, "Already refactored; refusing second run\n");
    exit(1);
}

// ------------------------------------------------------------
// 1. PHP tokenizer: identify real methods, not regex braces.
// ------------------------------------------------------------

$tokens = [];
$position = 0;

foreach (token_get_all($source, TOKEN_PARSE) as $raw) {
    $value = is_array($raw) ? $raw[1] : $raw;

    $tokens[] = [
        'id' => is_array($raw) ? $raw[0] : null,
        'text' => $value,
        'start' => $position,
        'end' => $position + strlen($value),
    ];

    $position += strlen($value);
}

$classBraceIndex = null;

foreach ($tokens as $i => $token) {
    if ($token['id'] !== T_CLASS) {
        continue;
    }

    $name = null;
    $j = $i + 1;

    for (; $j < count($tokens); $j++) {
        if ($tokens[$j]['id'] === T_STRING) {
            $name = $tokens[$j]['text'];
            break;
        }

        if ($tokens[$j]['text'] === '{') {
            break;
        }
    }

    if ($name !== 'CheckoutCodTtnInvestigationCaseTest') {
        continue;
    }

    for (; $j < count($tokens); $j++) {
        if ($tokens[$j]['text'] === '{') {
            $classBraceIndex = $j;
            break;
        }
    }

    break;
}

if ($classBraceIndex === null) {
    fwrite(STDERR, "Class opening brace not found\n");
    exit(1);
}

$isOpen = static fn (array $token): bool =>
    $token['text'] === '{'
    || $token['id'] === T_CURLY_OPEN
    || $token['id'] === T_DOLLAR_OPEN_CURLY_BRACES;

// ------------------------------------------------------------
// 2. Extract only public test_* methods.
// Helpers and setUp stay in the original class.
// ------------------------------------------------------------

$methods = [];
$depth = 1;
$classEnd = null;

for (
    $i = $classBraceIndex + 1, $n = count($tokens);
    $i < $n;
    $i++
) {
    $token = $tokens[$i];

    if ($depth === 1 && $token['id'] === T_FUNCTION) {
        $methodName = null;

        for ($j = $i + 1; $j < $n; $j++) {
            if ($tokens[$j]['id'] === T_STRING) {
                $methodName = $tokens[$j]['text'];
                break;
            }

            if ($tokens[$j]['text'] === '(') {
                break;
            }
        }

        if (
            $methodName !== null
            && str_starts_with($methodName, 'test_')
        ) {
            $visibilityIndex = null;

            for ($j = $i - 1; $j > $classBraceIndex; $j--) {
                $id = $tokens[$j]['id'];

                if ($id === T_PUBLIC) {
                    $visibilityIndex = $j;
                    break;
                }

                if (!in_array(
                    $id,
                    [T_WHITESPACE, T_FINAL, T_STATIC, T_ABSTRACT],
                    true
                )) {
                    break;
                }
            }

            if ($visibilityIndex === null) {
                fwrite(
                    STDERR,
                    "Unsupported test declaration: {$methodName}\n"
                );
                exit(1);
            }

            $prefix = substr(
                $source,
                0,
                $tokens[$visibilityIndex]['start']
            );

            $newline = strrpos($prefix, "\n");
            $start = $newline === false ? 0 : $newline + 1;

            $linePrefix = substr(
                $source,
                $start,
                $tokens[$visibilityIndex]['start'] - $start
            );

            if (trim($linePrefix) !== '') {
                fwrite(
                    STDERR,
                    "Unexpected declaration indentation: {$methodName}\n"
                );
                exit(1);
            }

            // Avoid detaching PHP attributes from test methods.
            $beforeLines = array_slice(
                explode("\n", rtrim(substr($source, 0, $start))),
                -4
            );

            if (preg_match(
                '/^\s*#\[/m',
                implode("\n", $beforeLines)
            )) {
                fwrite(
                    STDERR,
                    "PHP attribute requires manual migration: {$methodName}\n"
                );
                exit(1);
            }

            // Avoid losing PHPUnit metadata from docblocks.
            for ($k = $visibilityIndex - 1; $k >= 0; $k--) {
                if ($tokens[$k]['id'] === T_WHITESPACE) {
                    continue;
                }

                if (
                    $tokens[$k]['id'] === T_DOC_COMMENT
                    && preg_match(
                        '/@(dataProvider|depends|group|test|covers|requires)\b/i',
                        $tokens[$k]['text']
                    )
                ) {
                    fwrite(
                        STDERR,
                        "Annotated test requires manual migration: {$methodName}\n"
                    );
                    exit(1);
                }

                break;
            }

            $bodyStart = null;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j]['text'] === '{') {
                    $bodyStart = $j;
                    break;
                }

                if ($tokens[$j]['text'] === ';') {
                    break;
                }
            }

            if ($bodyStart === null) {
                fwrite(
                    STDERR,
                    "Test method body not found: {$methodName}\n"
                );
                exit(1);
            }

            $inner = 0;
            $end = null;

            for ($j = $bodyStart; $j < $n; $j++) {
                if ($isOpen($tokens[$j])) {
                    $inner++;
                } elseif ($tokens[$j]['text'] === '}') {
                    $inner--;

                    if ($inner === 0) {
                        $end = $tokens[$j]['end'];
                        break;
                    }
                }
            }

            if ($end === null) {
                fwrite(
                    STDERR,
                    "Unbalanced test method: {$methodName}\n"
                );
                exit(1);
            }

            $methods[] = [
                'name' => $methodName,
                'start' => $start,
                'end' => $end,
                'body' => substr($source, $start, $end - $start),
            ];
        }
    }

    if ($isOpen($token)) {
        $depth++;
    } elseif ($token['text'] === '}') {
        $depth--;

        if ($depth === 0) {
            $classEnd = $token['start'];
            break;
        }
    }
}

if ($classEnd === null || count($methods) < 20) {
    fwrite(STDERR, "Unsafe class structure or too few tests\n");
    exit(1);
}

// ------------------------------------------------------------
// 3. Assign tests to domains.
// ------------------------------------------------------------

$category = static function (string $name): string {
    if (str_starts_with($name, 'test_cod_return52_')) {
        return 'Returns52';
    }

    if (str_starts_with($name, 'test_cod_life51_')) {
        return 'Lifecycle51';
    }

    if (str_starts_with($name, 'test_cod_seal50_')) {
        return 'Settlement50';
    }

    if (preg_match(
        '/^test_(?:cod_)?(?:bank|privat|statement|posting|journal|refetch|adjustment)/',
        $name
    )) {
        return 'Bank';
    }

    if (preg_match(
        '/^test_(?:cod_)?(?:financial|gateway)/',
        $name
    )) {
        return 'Financial';
    }

    if (preg_match(
        '/^test_(?:cod_)?novapay/',
        $name
    )) {
        return 'NovaPay';
    }

    if (preg_match(
        '/^test_(?:cod_)?settlement/',
        $name
    )) {
        return 'Settlement';
    }

    if (preg_match(
        '/^test_(?:cod_)?(?:fulfillment|warehouse|internal|dispatch|tracking|safety|resolution|investigation|retry|evidence)/',
        $name
    )) {
        return 'Logistics';
    }

    return 'Core';
};

$groups = [];

foreach ($methods as $method) {
    $group = $category($method['name']);
    $groups[$group][] = $method;
}

// ------------------------------------------------------------
// 4. Split big groups into manageable traits.
// Max 40 test methods or approximately 1400 lines.
// ------------------------------------------------------------

$traits = [];
$generated = [];

foreach ($groups as $group => $items) {
    $chunks = [];
    $chunk = [];
    $lines = 0;

    foreach ($items as $item) {
        $itemLines = substr_count($item['body'], "\n") + 1;

        if (
            $chunk !== []
            && (
                count($chunk) >= 40
                || $lines + $itemLines > 1400
            )
        ) {
            $chunks[] = $chunk;
            $chunk = [];
            $lines = 0;
        }

        $chunk[] = $item;
        $lines += $itemLines;
    }

    if ($chunk !== []) {
        $chunks[] = $chunk;
    }

    foreach ($chunks as $index => $chunkMethods) {
        $trait = sprintf(
            'Cod52%sTestsPart%02d',
            $group,
            $index + 1
        );

        $traits[] = $trait;

        $content = rtrim($traitHeader)
            . "\n\ntrait {$trait}\n{\n"
            . implode(
                "\n\n",
                array_map(
                    static fn (array $method): string =>
                    rtrim($method['body']),
                    $chunkMethods
                )
            )
            . "\n}\n";

        $path = $outDir . '/' . $trait . '.php';

        $generated[$path] = $content;
    }
}

// ------------------------------------------------------------
// 5. Remove migrated methods from the main class.
// Reverse order preserves the original byte offsets.
// ------------------------------------------------------------

$rewritten = $source;

foreach (array_reverse($methods) as $method) {
    $rewritten = substr_replace(
        $rewritten,
        '',
        $method['start'],
        $method['end'] - $method['start']
    );
}

// Insert trait imports inside the original class.
$classBracePosition = $tokens[$classBraceIndex]['end'];

$uses = "\n";

foreach ($traits as $trait) {
    $uses .=
        "    use \\Tests\\Feature\\Concerns\\{$trait};\n";
}

$rewritten = substr_replace(
    $rewritten,
    $uses,
    $classBracePosition,
    0
);

// ------------------------------------------------------------
// 6. Preview and safety checks.
// ------------------------------------------------------------

foreach ($generated as $path => $_) {
    if (is_file($path)) {
        fwrite(
            STDERR,
            "Refusing to overwrite existing file: {$path}\n"
        );
        exit(1);
    }
}

echo "\nBOARDRIDERS — BLOCK 52.1\n";
echo $apply ? "MODE: APPLY\n" : "MODE: PREVIEW\n";

echo 'Original lines: '
    . number_format(substr_count($source, "\n") + 1)
    . "\n";

echo 'Test methods: '
    . count($methods)
    . "\n";

echo 'New trait files: '
    . count($traits)
    . "\n";

echo 'Remaining main file lines: '
    . number_format(substr_count($rewritten, "\n") + 1)
    . "\n\n";

foreach ($groups as $group => $items) {
    echo sprintf(
        "  %-16s %d tests\n",
        $group,
        count($items)
    );
}

if (!$apply) {
    echo "\nPreview complete. No files modified.\n";
    echo "Run with --apply to perform refactoring.\n";
    exit(0);
}

// ------------------------------------------------------------
// 7. Apply. Back up the original file outside the repository.
// ------------------------------------------------------------

$backupPath = sys_get_temp_dir()
    . '/boardriders-feature-before-52-1-'
    . date('Ymd-His')
    . '-'
    . substr(hash('sha256', $source), 0, 12)
    . '.bak';

if (
    file_put_contents(
        $backupPath,
        $source,
        LOCK_EX
    ) !== strlen($source)
) {
    fwrite(STDERR, "Backup failed; no changes made\n");
    exit(1);
}

if (
    !is_dir($outDir)
    && !mkdir($outDir, 0775, true)
    && !is_dir($outDir)
) {
    fwrite(STDERR, "Cannot create output directory\n");
    exit(1);
}

foreach ($generated as $path => $content) {
    if (
        file_put_contents(
            $path,
            $content,
            LOCK_EX
        ) !== strlen($content)
    ) {
        fwrite(STDERR, "Writing trait failed: {$path}\n");
        exit(1);
    }
}

if (
    file_put_contents(
        $sourceFile,
        $rewritten,
        LOCK_EX
    ) !== strlen($rewritten)
) {
    fwrite(STDERR, "Writing main test file failed\n");
    exit(1);
}

echo "\nRefactoring completed.\n";
echo "Backup: {$backupPath}\n";
echo "Business logic: unchanged\n";
echo "Test methods moved: " . count($methods) . "\n";
echo "Next step: composer dump-autoload\n";
