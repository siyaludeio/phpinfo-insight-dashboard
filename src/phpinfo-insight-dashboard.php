<?php
declare(strict_types=1);

const FALLBACK_TOKEN = 'siyalude-phpinfo-viewer-token';

function verify_token_hash(string $token): bool
{
    // Check if piid.txt exists in the same directory as this script
    $piidFile = __DIR__ . '/piid.txt';
    if (!file_exists($piidFile)) {
        return false; // No hash file, use fallback
    }

    // Read the hash from file
    $storedHash = trim(file_get_contents($piidFile));
    if (empty($storedHash)) {
        return false;
    }

    // Hash the provided token and compare
    $tokenHash = hash('sha256', $token);
    return hash_equals($storedHash, $tokenHash);
}

function expected_token(): string
{
    // Prefer getenv() for broad compatibility (FPM/CLI/Apache), then $_ENV as fallback.
    $t = getenv('PHPINFO_TOKEN');
    if (is_string($t) && $t !== '') return $t;

    $t2 = $_ENV['PHPINFO_TOKEN'] ?? '';
    if (is_string($t2) && $t2 !== '') return $t2;

    return FALLBACK_TOKEN;
}

function is_token_valid(?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }

    // Check if piid.txt exists
    $piidFile = __DIR__ . '/piid.txt';
    if (!file_exists($piidFile)) {
        // No hash file, use default token check
        return hash_equals(expected_token(), $token);
    }

    // Verify against hash in piid.txt
    return verify_token_hash($token);
}

function normalize_boolish(mixed $value): mixed
{
    if (!is_string($value)) return $value;

    $v = strtolower(trim($value));
    if ($v === 'on') return true;
    if ($v === 'off') return false;
    if ($v === 'enabled') return true;
    if ($v === 'disabled') return false;
    if ($v === '') return '';
    return $value;
}

function phpinfo_to_array(int $what = INFO_ALL): array
{
    // If DOM extension is missing, we can’t parse phpinfo HTML.
    if (!class_exists('DOMDocument')) {
        return [
            'error' => 'DOM extension is not available; cannot parse phpinfo().',
            'hint'  => 'Enable ext-dom (php-dom) and reload.',
            'loaded_extensions' => get_loaded_extensions(),
        ];
    }

    ob_start();
    phpinfo($what);
    $html = ob_get_clean() ?: '';

    // Best-effort to avoid broken UTF-8 sequences in phpinfo output.
    // This helps with weird glyphs like those you saw under xdebug.
    if (function_exists('mb_convert_encoding')) {
        // Ensure DOMDocument treats input as UTF-8 (no entity conversion needed)
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    } elseif (function_exists('iconv')) {
        $html = @iconv('UTF-8', 'UTF-8//IGNORE', $html) ?: $html;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $result = [];

    // phpinfo sections usually render as <h2>Section</h2> followed by one or more tables.
    foreach ($xpath->query('//h2') as $h2) {
        $section = trim($h2->textContent ?? '');
        if ($section === '') continue;

        $sectionData = [];

        // Collect tables after this <h2> until the next <h2>
        $node = $h2->nextSibling;
        while ($node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'h2') {
                break;
            }

            if ($node instanceof DOMElement && strtolower($node->tagName) === 'table') {
                // Parse rows
                foreach ($xpath->query('.//tr', $node) as $tr) {
                    $ths = $xpath->query('.//th', $tr);
                    $tds = $xpath->query('.//td', $tr);

                    // Two common phpinfo table patterns:
                    // 1) "Key" => "Value" (2 columns)
                    // 2) INI "Directive" => "Local Value" + "Master Value" (3 columns)
                    if ($ths->length === 1 && $tds->length === 1) {
                        $k = trim($ths->item(0)?->textContent ?? '');
                        $v = trim($tds->item(0)?->textContent ?? '');
                        if ($k !== '') $sectionData[$k] = normalize_boolish($v);
                    } elseif ($ths->length === 1 && $tds->length === 2) {
                        $k = trim($ths->item(0)?->textContent ?? '');
                        $local  = trim($tds->item(0)?->textContent ?? '');
                        $master = trim($tds->item(1)?->textContent ?? '');
                        if ($k !== '') {
                            $sectionData[$k] = [
                                'local'  => normalize_boolish($local),
                                'master' => normalize_boolish($master),
                            ];
                        }
                    } elseif ($ths->length === 0 && $tds->length >= 2) {
                        // Some sections may render as td/td without th.
                        $k = trim($tds->item(0)?->textContent ?? '');
                        $v = trim($tds->item(1)?->textContent ?? '');
                        if ($k !== '') $sectionData[$k] = normalize_boolish($v);
                    }
                }
            }

            $node = $node->nextSibling;
        }

        // Keep section even if empty (sometimes useful)
        $result[$section] = $sectionData;
    }

    // php -m equivalent (inside a script): get_loaded_extensions()
    $loaded_extensions = get_loaded_extensions();
    sort($loaded_extensions, SORT_NATURAL | SORT_FLAG_CASE);
    $result['loaded_extensions'] = $loaded_extensions;

    // Also include quick meta
    $result['_meta'] = [
        'generated_at' => gmdate('c'),
        'php_version'  => PHP_VERSION,
        'sapi'         => PHP_SAPI,
        'binary'       => PHP_BINARY,
        'os'           => PHP_OS_FAMILY . ' (' . PHP_OS . ')',
        'ini_loaded'   => php_ini_loaded_file() ?: null,
        'ini_scanned'  => php_ini_scanned_files() ?: null,
        'memory_limit' => ini_get('memory_limit') ?: null,
        'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
        'post_max_size' => ini_get('post_max_size') ?: null,
        'max_execution_time' => ini_get('max_execution_time') ?: null,
        'timezone' => date_default_timezone_get() ?: null,
    ];

    return $result;
}

function send_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// JSON endpoint
if (isset($_GET['json'])) {
    $token = (string)($_GET['token'] ?? '');
    if (!is_token_valid($token)) {
        send_json(['error' => 'Forbidden'], 403);
        exit;
    }

    // You can limit phpinfo exposure by changing INFO_ALL -> INFO_CONFIGURATION etc.
    $payload = phpinfo_to_array(INFO_ALL);
    send_json($payload);
    exit;
}

// Default to current file with json parameter
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$apiUrl = $_GET['api'] ?? ($scriptName . '?json=1'); // your backend endpoint
$token  = $_GET['token'] ?? '';                // optional pass-through token

// Check if token is valid for UI display
$isTokenValid = is_token_valid($token);

// Set 404 status if token is invalid
if (!$isTokenValid) {
    http_response_code(404);
}

// Logo base64 - set this variable manually with your logo's base64 string
// Example: $logoBase64 = 'iVBORw0KGgoAAAANSUhEUgAA...'; // your base64 string here
if (!isset($logoBase64)) {
    $logoBase64 = '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';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>PHPINFO Insight Dashboard | Siyalude IO</title>

    <!-- Tailwind v4 CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>

    <!-- Alpine -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50/30 to-indigo-50/50 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 text-slate-900 dark:text-slate-50 antialiased transition-colors duration-300">
<div class="mx-auto max-w-6xl px-4 py-10"
     x-data="phpinfoViewer(<?= htmlspecialchars(json_encode(['apiUrl' => $apiUrl, 'token' => $token, 'isTokenValid' => $isTokenValid], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>)"
     x-init="init(); initTheme()"
>
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
        <div class="flex items-center gap-4">
            <!-- Logo -->
            <?php if (!empty($logoBase64)): ?>
            <div class="flex items-center gap-3">
                <img src="data:image/png;base64,<?= htmlspecialchars($logoBase64) ?>"
                     alt="Siyalude Logo"
                     class="h-12 w-auto object-contain rounded-lg"
                     style="max-height: 48px;">
            </div>
            <?php else: ?>
            <div class="flex items-center gap-3">
                <div class="text-4xl font-bold">
                    <span class="text-blue-900 dark:text-blue-400">SIY</span>
                    <span class="text-red-600 dark:text-red-500">A</span>
                    <span class="text-blue-900 dark:text-blue-400">LUDE</span>
                </div>
            </div>
            <?php endif; ?>
            <div class="h-12 w-px bg-zinc-300 dark:bg-zinc-700"></div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">
                    <span class="text-blue-600 dark:text-blue-400">PHPINFO</span>
                    <span class="text-purple-600 dark:text-purple-400">Insight</span>
                    <span class="text-cyan-600 dark:text-cyan-400">Dashboard</span>
                </h1>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1.5">Intuitive runtime visualization & comprehensive extension explorer</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <!-- Theme Toggle -->
            <button
                type="button"
                class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-800/90 backdrop-blur-sm px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-700/50 transition shadow-sm hover:shadow"
                @click="toggleTheme()"
                title="Toggle theme"
            >
                <svg x-show="isDark" class="size-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <svg x-show="!isDark" class="size-5 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                </svg>
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-800/90 backdrop-blur-sm px-3 py-2 text-sm text-slate-700 dark:text-slate-200 transition shadow-sm"
                :class="!isTokenValid ? 'opacity-50 cursor-not-allowed' : ''"
                @click="isTokenValid ? reload() : null"
            >
          <span class="size-2 rounded-full"
                :class="loading ? 'bg-amber-400' : (error ? 'bg-red-400' : (isTokenValid ? 'bg-emerald-400' : 'bg-red-400'))"></span>
                <span class="font-medium" x-text="loading ? 'Loading…' : (error ? 'Error' : (isTokenValid ? 'Ready' : 'Forbidden'))"></span>
            </button>

            <button
                type="button"
                class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-800/90 backdrop-blur-sm px-3 py-2 text-sm text-slate-700 dark:text-slate-200 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                :class="isTokenValid ? 'hover:bg-slate-50 dark:hover:bg-slate-700/50 hover:shadow' : ''"
                @click="copyJSON(raw, $event)"
                :disabled="!raw || loading || !isTokenValid"
            >
                Copy full JSON
            </button>
        </div>
    </div>

    <!-- Error -->
    <template x-if="error">
        <div class="mt-6 rounded-2xl border border-red-500/30 bg-red-500/10 p-4">
            <div class="font-semibold">Failed to load JSON</div>
            <div class="mt-1 text-sm text-red-200/80 whitespace-pre-wrap" x-text="error"></div>
        </div>
    </template>

    <!-- Common (rich) -->
    <?php if ($isTokenValid): ?>
    <div class="mt-6 rounded-3xl border border-slate-200/80 dark:border-slate-700/50 bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm shadow-xl shadow-slate-200/50 dark:shadow-slate-900/50 p-6">
        <div class="flex items-start justify-between gap-4 mb-5">
            <div>
                <div class="text-base font-bold bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 bg-clip-text text-transparent">Common</div>
                <div class="text-xs text-slate-600 dark:text-slate-400 mt-1.5">Most-used info surfaced from Core/curl/openssl/Zend OPcache</div>
            </div>

            <div class="text-xs text-slate-500 dark:text-slate-400 text-right">
                <div class="font-medium text-slate-400 dark:text-slate-500">Generated</div>
                <div class="text-slate-700 dark:text-slate-300 mt-0.5 font-mono" x-text="common.generated_at ?? '—'"></div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <!-- PHP -->
            <div class="rounded-2xl border border-blue-200/60 dark:border-blue-500/30 bg-gradient-to-br from-blue-50/80 to-cyan-50/80 dark:from-blue-950/40 dark:to-cyan-950/40 p-5 shadow-md hover:shadow-lg transition-shadow backdrop-blur-sm">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-blue-500 dark:bg-blue-400"></div>
                    <div class="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">PHP</div>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Version</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 font-mono text-xs" x-text="common.php_version ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">SAPI</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.sapi ?? '—'"></span>
                    </div>
                    <div>
                        <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">Binary</div>
                        <div class="text-slate-900 dark:text-slate-200 break-words text-xs font-mono" x-text="common.binary ?? '—'"></div>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Extensions</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 text-xs" x-text="common.extensions_count ?? extensions.length"></span>
                    </div>
                </div>
            </div>

            <!-- Platform -->
            <div class="rounded-2xl border border-purple-200/60 dark:border-purple-500/30 bg-gradient-to-br from-purple-50/80 to-pink-50/80 dark:from-purple-950/40 dark:to-pink-950/40 p-5 shadow-md hover:shadow-lg transition-shadow backdrop-blur-sm">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-purple-500 dark:bg-purple-400"></div>
                    <div class="text-xs font-bold uppercase tracking-wide text-purple-700 dark:text-purple-300">Platform</div>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">OS</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.os ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Timezone</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs font-mono" x-text="common.timezone ?? '—'"></span>
                    </div>
                    <div>
                        <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">Ext dir</div>
                        <div class="text-slate-900 dark:text-slate-200 break-words text-xs font-mono" x-text="common.extension_dir ?? '—'"></div>
                    </div>
                </div>
            </div>

            <!-- Limits -->
            <div class="rounded-2xl border border-amber-200/60 dark:border-amber-500/30 bg-gradient-to-br from-amber-50/80 to-orange-50/80 dark:from-amber-950/40 dark:to-orange-950/40 p-5 shadow-md hover:shadow-lg transition-shadow backdrop-blur-sm">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-amber-500 dark:bg-amber-400"></div>
                    <div class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Limits</div>
                </div>
                <div class="space-y-2.5 text-sm">
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Memory</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 text-xs" x-text="common.memory_limit ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Upload</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 text-xs" x-text="common.upload_max_filesize ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Post</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 text-xs" x-text="common.post_max_size ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Max exec</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 text-xs" x-text="common.max_execution_time ?? '—'"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- INI + Crypto + Opcache -->
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="rounded-2xl border border-emerald-200/60 dark:border-emerald-500/30 bg-gradient-to-br from-emerald-50/80 to-teal-50/80 dark:from-emerald-950/40 dark:to-teal-950/40 p-5 shadow-md hover:shadow-lg transition-shadow backdrop-blur-sm">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></div>
                    <div class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">INI</div>
                </div>
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">Loaded php.ini</div>
                        <div class="text-slate-900 dark:text-slate-200 break-words text-xs font-mono" x-text="common.ini_loaded_file ?? '—'"></div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-600 dark:text-slate-400 mb-1">Scanned INI files</div>
                        <div class="text-slate-900 dark:text-slate-200 break-words text-xs font-mono" x-text="common.ini_scanned_files ?? '—'"></div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-cyan-200/60 dark:border-cyan-500/30 bg-gradient-to-br from-cyan-50/80 to-blue-50/80 dark:from-cyan-950/40 dark:to-blue-950/40 p-5 shadow-md hover:shadow-lg transition-shadow backdrop-blur-sm">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-cyan-500 dark:bg-cyan-400"></div>
                    <div class="text-xs font-bold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Crypto</div>
                </div>
                <div class="space-y-2.5 text-sm">
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">OpenSSL</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.openssl_library ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">cURL</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.curl_version ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">cURL SSL</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.curl_ssl_version ?? '—'"></span>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-violet-200/60 dark:border-violet-500/30 bg-gradient-to-br from-violet-50/80 to-indigo-50/80 dark:from-violet-950/40 dark:to-indigo-950/40 p-5 shadow-md hover:shadow-lg transition-shadow backdrop-blur-sm">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-violet-500 dark:bg-violet-400"></div>
                    <div class="text-xs font-bold uppercase tracking-wide text-violet-700 dark:text-violet-300">OPcache</div>
                </div>
                <div class="space-y-2.5 text-sm">
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Status</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.opcache_status ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">JIT</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate text-xs" x-text="common.opcache_jit ?? '—'"></span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-slate-600 dark:text-slate-400 text-xs">Cache hits</span>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 text-xs" x-text="common.opcache_hits ?? '—'"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <?php if ($isTokenValid): ?>
    <div class="mt-6 rounded-3xl border border-slate-200/80 dark:border-slate-700/50 bg-white/80 dark:bg-slate-800/50 backdrop-blur-sm shadow-xl shadow-slate-200/50 dark:shadow-slate-900/50 p-6">
        <div class="flex items-center gap-1 border-b border-slate-200 dark:border-slate-700 mb-5 pb-1">
            <button
                type="button"
                class="px-4 py-2.5 text-sm font-semibold transition rounded-t-xl relative"
                :class="activeTab === 'extensions' ? 'text-indigo-600 dark:text-indigo-400 bg-indigo-50/80 dark:bg-indigo-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'"
                @click="activeTab = 'extensions'"
            >
                <span>Loaded Extensions</span>
                <span class="ml-2 text-xs font-normal opacity-70" x-text="'(' + (loadedExtensions?.length ?? 0) + ')'"></span>
                <span x-show="activeTab === 'extensions'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-indigo-600 dark:bg-indigo-400 rounded-full"></span>
            </button>
            <button
                type="button"
                class="px-4 py-2.5 text-sm font-semibold transition rounded-t-xl relative"
                :class="activeTab === 'sections' ? 'text-indigo-600 dark:text-indigo-400 bg-indigo-50/80 dark:bg-indigo-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'"
                @click="activeTab = 'sections'"
            >
                <span>Sections</span>
                <span class="ml-2 text-xs font-normal opacity-70" x-text="'(' + extensions.length + ')'"></span>
                <span x-show="activeTab === 'sections'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-indigo-600 dark:bg-indigo-400 rounded-full"></span>
            </button>
        </div>

        <!-- Loaded Extensions Tab Content -->
        <div x-show="activeTab === 'extensions'">
            <div class="mb-4">
                <label class="text-xs text-zinc-600 dark:text-zinc-400">Search Extensions</label>
                <div class="mt-2 flex items-center gap-2 rounded-xl border border-zinc-300 dark:border-white/10 bg-white dark:bg-zinc-950/40 px-3 py-2">
                    <svg class="size-4 text-zinc-500 dark:text-zinc-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 104.27 9.02l2.1 2.1a.75.75 0 101.06-1.06l-2.1-2.1A5.5 5.5 0 009 3.5zM5 9a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd"/>
                    </svg>
                    <input
                        type="text"
                        class="w-full bg-transparent outline-none text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-500 dark:placeholder:text-zinc-600"
                        placeholder="e.g. curl, openssl, pdo, mysqli…"
                        x-model.debounce.150ms="extensionQuery"
                    />
                    <button class="text-xs text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
                            x-show="extensionQuery.length"
                            @click="extensionQuery='';"
                            type="button">
                        Clear
                    </button>
                </div>
                <div class="mt-2 text-xs text-zinc-600 dark:text-zinc-500">
                    Showing <span class="text-zinc-700 dark:text-zinc-300" x-text="filteredLoadedExtensions.length"></span>
                    of <span class="text-zinc-700 dark:text-zinc-300" x-text="loadedExtensions?.length ?? 0"></span> extensions
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/30 overflow-hidden backdrop-blur-sm">
                <div class="max-h-[600px] overflow-auto p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                        <template x-for="ext in filteredLoadedExtensions" :key="ext">
                            <div class="rounded-lg border px-3 py-2 text-sm font-medium transition shadow-sm hover:shadow"
                                 :class="isNonDefaultExtension(ext)
                                     ? 'border-amber-300 dark:border-amber-600/50 bg-amber-50 dark:bg-amber-950/30 text-slate-900 dark:text-slate-200 hover:bg-amber-100 dark:hover:bg-amber-900/40 hover:border-amber-400 dark:hover:border-amber-500'
                                     : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/50 text-slate-900 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700/50 hover:border-slate-300 dark:hover:border-slate-600'">
                                <div class="flex items-center justify-between gap-2 cursor-default">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="truncate" x-text="ext"></span>
                                        <template x-if="isNonDefaultExtension(ext) && getExtensionInfo(ext)">
                                            <span class="text-xs text-slate-600 dark:text-slate-400 font-normal"
                                                  x-text="'(' + getExtensionInfo(ext).package + ')'"></span>
                                        </template>
                                    </div>
                                    <button x-show="isNonDefaultExtension(ext)"
                                            @click.stop="toggleExtensionInfo(ext)"
                                            class="text-amber-600 dark:text-amber-400 hover:text-amber-700 dark:hover:text-amber-300 transition shrink-0"
                                            title="Toggle extension info">
                                        <svg class="size-3.5 transition-transform"
                                             :class="isExtensionExpanded(ext) ? 'rotate-180' : ''"
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                </div>
                                <template x-if="isNonDefaultExtension(ext) && isExtensionExpanded(ext) && getExtensionInfo(ext)">
                                    <div class="mt-2 pt-2 border-t border-amber-200 dark:border-amber-700/50 space-y-1.5">
                                        <p class="text-xs text-slate-700 dark:text-slate-300 leading-relaxed"
                                           x-text="getExtensionInfo(ext).desc"></p>
                                        <a :href="getExtensionInfo(ext).url"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           @click.stop.prevent="window.open(getExtensionInfo(ext).url, '_blank', 'noopener,noreferrer')"
                                           class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium">
                                            <span>Documentation</span>
                                            <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!loading && !error && filteredLoadedExtensions.length === 0">
                            <div class="col-span-full text-center text-sm text-zinc-600 dark:text-zinc-400 py-8">
                                No extensions found.
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sections Tab Content -->
        <div x-show="activeTab === 'sections'">
            <div class="mb-4">
                <label class="text-xs font-semibold text-slate-700 dark:text-slate-300 mb-2 block">Search (name + keys + values)</label>
                <div class="flex items-center gap-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/50 px-4 py-2.5 shadow-sm focus-within:ring-2 focus-within:ring-indigo-500/20 focus-within:border-indigo-300 dark:focus-within:border-indigo-600 transition">
                    <svg class="size-4 text-slate-400 dark:text-slate-500" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 104.27 9.02l2.1 2.1a.75.75 0 101.06-1.06l-2.1-2.1A5.5 5.5 0 009 3.5zM5 9a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd"/>
                    </svg>
                    <input
                        type="text"
                        class="w-full bg-transparent outline-none text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                        placeholder="e.g. opcache, curl, memory_limit, /tmp, OpenSSL…"
                        x-model.debounce.150ms="query"
                    />
                    <button class="text-xs text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition"
                            x-show="query.length"
                            @click="query='';"
                            type="button">
                        Clear
                    </button>
                </div>
                <div class="mt-2.5 text-xs text-slate-600 dark:text-slate-400 flex items-center justify-between">
                    <div>
                        Showing <span class="text-zinc-700 dark:text-zinc-300" x-text="filteredExtensions.length"></span>
                        of <span class="text-zinc-700 dark:text-zinc-300" x-text="extensions.length"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-zinc-300 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-white/10 transition disabled:opacity-50 disabled:cursor-not-allowed"
                            @click="expandAll()"
                            :disabled="filteredExtensions.length === 0"
                        >
                            Expand all
                        </button>
                        <button
                            type="button"
                            class="rounded-xl border border-zinc-300 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-white/10 transition disabled:opacity-50 disabled:cursor-not-allowed"
                            @click="collapseAll()"
                            :disabled="filteredExtensions.length === 0"
                        >
                            Collapse
                        </button>
                    </div>
                </div>
            </div>
            <div class="space-y-2">
        <template x-for="ext in filteredExtensions" :key="ext.name">
            <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-white/5 overflow-hidden shadow-md">
                <button
                    type="button"
                    class="w-full flex items-start justify-between gap-3 px-4 py-3 text-left hover:bg-zinc-50 dark:hover:bg-white/5 transition"
                    @click="toggleExtension(ext.name)"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <div class="font-semibold truncate"
                                 :class="isNonDefaultExtension(ext.name)
                                     ? 'text-amber-700 dark:text-amber-400'
                                     : 'text-zinc-900 dark:text-white'"
                                 x-text="ext.name"></div>
                            <template x-if="isNonDefaultExtension(ext.name) && getExtensionInfo(ext.name)">
                                <span class="text-xs text-zinc-600 dark:text-zinc-400 font-normal"
                                      x-text="'(' + getExtensionInfo(ext.name).package + ')'"></span>
                            </template>
                            <span class="text-[11px] px-2 py-0.5 rounded-full border border-zinc-300 dark:border-white/10 bg-zinc-100 dark:bg-white/5 text-zinc-700 dark:text-white/70"
                                  x-show="ext.version"
                                  x-text="ext.version"></span>
                            <button x-show="isNonDefaultExtension(ext.name)"
                                    @click.stop="toggleExtensionInfo(ext.name)"
                                    class="text-amber-600 dark:text-amber-400 hover:text-amber-700 dark:hover:text-amber-300 transition shrink-0"
                                    title="Toggle extension info">
                                <svg class="size-3.5 transition-transform"
                                     :class="isExtensionExpanded(ext.name) ? 'rotate-180' : ''"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                            <span x-text="ext.count + ' entries'"></span>
                            <span class="text-zinc-600">•</span>
                            <span x-text="ext.typesSummary"></span>
                        </div>
                        <template x-if="isNonDefaultExtension(ext.name) && isExtensionExpanded(ext.name) && getExtensionInfo(ext.name)">
                            <div class="mt-2 pt-2 border-t border-zinc-200 dark:border-white/10 space-y-1.5">
                                <p class="text-xs text-zinc-700 dark:text-zinc-300 leading-relaxed"
                                   x-text="getExtensionInfo(ext.name).desc"></p>
                                <a :href="getExtensionInfo(ext.name).url"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   @click.stop.prevent="window.open(getExtensionInfo(ext.name).url, '_blank', 'noopener,noreferrer')"
                                   class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium">
                                    <span>Documentation</span>
                                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </a>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
              <span class="text-[11px] text-zinc-600 dark:text-zinc-400"
                    x-show="query.length"
                    x-text="ext.matches + ' match' + (ext.matches===1?'':'es')"></span>

                        <svg class="size-4 text-zinc-600 dark:text-white/60 transition-transform"
                             :class="(expandedAll || openExt === ext.name) ? 'rotate-180' : ''"
                             viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                  d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"
                                  clip-rule="evenodd" />
                        </svg>
                    </div>
                </button>

                <div x-show="expandedAll || openExt === ext.name"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="border-t border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-black/20">
                    <div class="p-4 space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="text-xs px-3 py-1.5 rounded-xl border border-zinc-300 dark:border-white/10 bg-white dark:bg-white/5 hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-700 dark:text-white/80"
                                @click.stop="copyJSON(ext.raw, $event)"
                            >
                                Copy JSON
                            </button>
                            <button
                                type="button"
                                class="text-xs px-3 py-1.5 rounded-xl border border-zinc-300 dark:border-white/10 bg-white dark:bg-white/5 hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-700 dark:text-white/80"
                                @click.stop="downloadJSON(ext.name, ext.raw)"
                            >
                                Download
                            </button>
                        </div>

                        <div class="rounded-2xl border border-zinc-200 dark:border-white/10 overflow-hidden">
                            <div class="max-h-[420px] overflow-auto">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 bg-zinc-100 dark:bg-black/40 backdrop-blur border-b border-zinc-200 dark:border-white/10">
                                    <tr class="text-zinc-700 dark:text-white/60">
                                        <th class="text-left font-medium px-3 py-2 w-[42%]">Key</th>
                                        <th class="text-left font-medium px-3 py-2">Value</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <template x-for="row in ext.rows" :key="row.k">
                                        <tr class="border-b border-zinc-200 dark:border-white/5 hover:bg-zinc-50 dark:hover:bg-white/5">
                                            <td class="px-3 py-2 text-zinc-900 dark:text-white/80 align-top break-words" x-text="row.k"></td>
                                            <td class="px-3 py-2 text-zinc-800 dark:text-white/70 align-top break-words">
                                                <template x-if="row.type === 'scalar'">
                                                    <span x-text="row.v"></span>
                                                </template>

                                                <template x-if="row.type === 'directive'">
                                                    <div class="grid grid-cols-2 gap-2 text-xs">
                                                        <div class="rounded-xl border border-zinc-300 dark:border-white/10 bg-zinc-50 dark:bg-white/5 p-2">
                                                            <div class="text-zinc-600 dark:text-white/50">local</div>
                                                            <div class="text-zinc-900 dark:text-white/80 mt-0.5" x-text="row.v.local ?? '—'"></div>
                                                        </div>
                                                        <div class="rounded-xl border border-zinc-300 dark:border-white/10 bg-zinc-50 dark:bg-white/5 p-2">
                                                            <div class="text-zinc-600 dark:text-white/50">master</div>
                                                            <div class="text-zinc-900 dark:text-white/80 mt-0.5" x-text="row.v.master ?? '—'"></div>
                                                        </div>
                                                    </div>
                                                </template>

                                                <template x-if="row.type === 'json'">
                              <pre class="text-xs whitespace-pre-wrap break-words text-zinc-800 dark:text-white/70"
                                   x-text="JSON.stringify(row.v, null, 2)"></pre>
                                                </template>
                                            </td>
                                        </tr>
                                    </template>

                                    <template x-if="ext.rows.length === 0">
                                        <tr>
                                            <td colspan="2" class="px-4 py-8 text-sm text-slate-500 dark:text-slate-400 text-center">
                                                No rows
                                            </td>
                                        </tr>
                                    </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </template>

                <template x-if="!loading && !error && filteredExtensions.length === 0">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-8 text-center text-zinc-400">
                        No matches.
                    </div>
                </template>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="mt-12 pt-8 border-t border-zinc-300 dark:border-white/10">
        <!-- Disclaimer -->
        <div class="mb-6 p-4 rounded-2xl border border-amber-200 dark:border-amber-500/30 bg-amber-50/50 dark:bg-amber-500/10">
            <div class="flex items-start gap-3">
                <svg class="size-5 text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <div class="text-sm font-semibold text-amber-900 dark:text-amber-300 mb-1">Disclaimer</div>
                    <p class="text-xs text-amber-800 dark:text-amber-400/90">
                        This dashboard provides an intuitive visual representation of PHP runtime information.
                        There may be slight deviations from the actual <code class="px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-500/20 text-amber-900 dark:text-amber-300">phpinfo()</code> output.
                        For absolute accuracy, please refer to the native PHP function directly.
                    </p>
                </div>
            </div>
        </div>

        <!-- Credits -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-900 dark:text-slate-400">
            <div class="flex items-center gap-2">
                <span class="font-semibold">© <?= date('Y') ?> Siyalude Private Limited. All rights reserved.</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-semibold">Developed by</span>
                <a href="https://www.siyalude.io" target="_blank" rel="noopener noreferrer"
                   class="flex items-center gap-2 font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                    <?php if (!empty($logoBase64)): ?>
                        <img src="data:image/png;base64,<?= htmlspecialchars($logoBase64) ?>"
                             alt="Siyalude Logo"
                             class="h-5 w-auto object-contain rounded"
                             style="max-height: 20px;">
                    <?php else: ?>
                        <span class="text-base font-bold">
                            <span class="text-blue-700 dark:text-blue-400">SIY</span>
                            <span class="text-red-600 dark:text-red-500">A</span>
                            <span class="text-blue-700 dark:text-blue-400">LUDE</span>
                        </span>
                    <?php endif; ?>
                    <span class="text-slate-800 dark:text-slate-500">IO</span>
                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                </a>
            </div>
        </div>
    </footer>
</div>

<script>
    function phpinfoViewer({ apiUrl, token, isTokenValid }) {
        return {
            apiUrl,
            token,
            isTokenValid: isTokenValid !== undefined ? isTokenValid : true,

            loading: false,
            error: null,
            raw: null,

            query: '',
            openExt: null,
            expandedAll: false,
            activeTab: 'extensions',
            extensionQuery: '',
            isDark: true,

            common: {
                generated_at: null,
                php_version: null,
                sapi: null,
                binary: null,
                os: null,
                timezone: null,
                extensions_count: null,

                ini_loaded_file: null,
                ini_scanned_files: null,

                memory_limit: null,
                upload_max_filesize: null,
                post_max_size: null,
                max_execution_time: null,
                extension_dir: null,

                opcache_status: null,
                opcache_jit: null,
                opcache_hits: null,

                curl_version: null,
                curl_ssl_version: null,

                openssl_library: null,
            },

            extensions: [],
            loadedExtensions: [],

            // Default/core PHP extensions that come built-in (always available)
            // Extensions NOT in this list are considered non-default and require installation
            defaultExtensions: [
                'Core', 'date', 'libxml', 'openssl', 'pcre', 'zlib', 'filter', 'hash',
                'Reflection', 'SPL', 'standard', 'session', 'ctype', 'dom', 'fileinfo',
                'json', 'Phar', 'SimpleXML', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter',
                'bcmath', 'calendar', 'exif', 'gettext', 'iconv', 'pcntl', 'shmop',
                'sysvmsg', 'sysvsem', 'sysvshm'
            ],

            // Extension package names and descriptions
            extensionInfo: {
                // Database Extensions
                'curl': { package: 'php-curl', desc: 'Client URL library for making HTTP requests.', url: 'https://www.php.net/manual/en/book.curl.php' },
                'mysqli': { package: 'php-mysqli', desc: 'MySQL improved extension for database connectivity.', url: 'https://www.php.net/manual/en/book.mysqli.php' },
                'mysql': { package: 'php-mysql', desc: 'MySQL database extension (deprecated, use mysqli).', url: 'https://www.php.net/manual/en/book.mysql.php' },
                'pdo': { package: 'php-pdo', desc: 'PHP Data Objects - database access abstraction layer.', url: 'https://www.php.net/manual/en/book.pdo.php' },
                'pdo_mysql': { package: 'php-pdo-mysql', desc: 'PDO driver for MySQL database connections.', url: 'https://www.php.net/manual/en/ref.pdo-mysql.php' },
                'pdo_pgsql': { package: 'php-pdo-pgsql', desc: 'PDO driver for PostgreSQL database connections.', url: 'https://www.php.net/manual/en/ref.pdo-pgsql.php' },
                'pdo_sqlite': { package: 'php-pdo-sqlite', desc: 'PDO driver for SQLite database connections.', url: 'https://www.php.net/manual/en/ref.pdo-sqlite.php' },
                'pdo_oci': { package: 'php-pdo-oci', desc: 'PDO driver for Oracle database connections.', url: 'https://www.php.net/manual/en/ref.pdo-oci.php' },
                'pdo_firebird': { package: 'php-pdo-firebird', desc: 'PDO driver for Firebird database connections.', url: 'https://www.php.net/manual/en/ref.pdo-firebird.php' },
                'pdo_dblib': { package: 'php-pdo-dblib', desc: 'PDO driver for Microsoft SQL Server and Sybase.', url: 'https://www.php.net/manual/en/ref.pdo-dblib.php' },
                'pdo_odbc': { package: 'php-pdo-odbc', desc: 'PDO driver for ODBC database connections.', url: 'https://www.php.net/manual/en/ref.pdo-odbc.php' },
                'pdo_ibm': { package: 'php-pdo-ibm', desc: 'PDO driver for IBM DB2 database connections.', url: 'https://www.php.net/manual/en/ref.pdo-ibm.php' },
                'pgsql': { package: 'php-pgsql', desc: 'PostgreSQL database extension for direct database access.', url: 'https://www.php.net/manual/en/book.pgsql.php' },
                'sqlite3': { package: 'php-sqlite3', desc: 'SQLite3 database extension.', url: 'https://www.php.net/manual/en/book.sqlite3.php' },
                'mongodb': { package: 'php-mongodb', desc: 'MongoDB driver for NoSQL database connectivity.', url: 'https://www.php.net/manual/en/book.mongodb.php' },
                'oci8': { package: 'php-oci8', desc: 'Oracle Database extension for Oracle database connectivity.', url: 'https://www.php.net/manual/en/book.oci8.php' },
                'ibm_db2': { package: 'php-ibm-db2', desc: 'IBM DB2 database extension for DB2 database access.', url: 'https://www.php.net/manual/en/book.ibm-db2.php' },
                'dba': { package: 'php-dba', desc: 'Database abstraction layer for various database backends.', url: 'https://www.php.net/manual/en/book.dba.php' },

                // String and Text Processing
                'mbstring': { package: 'php-mbstring', desc: 'Multibyte string handling functions for character encoding.', url: 'https://www.php.net/manual/en/book.mbstring.php' },
                'intl': { package: 'php-intl', desc: 'Internationalization extension for Unicode and globalization.', url: 'https://www.php.net/manual/en/book.intl.php' },
                'iconv': { package: 'php-iconv', desc: 'Character encoding conversion functions.', url: 'https://www.php.net/manual/en/book.iconv.php' },
                'pspell': { package: 'php-pspell', desc: 'Spell checking library for text validation.', url: 'https://www.php.net/manual/en/book.pspell.php' },
                'enchant': { package: 'php-enchant', desc: 'Spell checking library using Enchant backend.', url: 'https://www.php.net/manual/en/book.enchant.php' },

                // Image Processing
                'gd': { package: 'php-gd', desc: 'Image processing and manipulation library.', url: 'https://www.php.net/manual/en/book.image.php' },
                'imagick': { package: 'php-imagick', desc: 'ImageMagick extension for advanced image processing.', url: 'https://www.php.net/manual/en/book.imagick.php' },
                'gmagick': { package: 'php-gmagick', desc: 'GraphicsMagick extension for image manipulation.', url: 'https://www.php.net/manual/en/book.gmagick.php' },
                'exif': { package: 'php-exif', desc: 'Exchangeable image file format metadata reading.', url: 'https://www.php.net/manual/en/book.exif.php' },

                // Compression and Archives
                'zip': { package: 'php-zip', desc: 'ZIP archive reading and writing functions.', url: 'https://www.php.net/manual/en/book.zip.php' },
                'bz2': { package: 'php-bz2', desc: 'Bzip2 compression and decompression functions.', url: 'https://www.php.net/manual/en/book.bzip2.php' },
                'rar': { package: 'php-rar', desc: 'RAR archive reading and extraction functions.', url: 'https://www.php.net/manual/en/book.rar.php' },
                'zlib': { package: 'php-zlib', desc: 'Zlib compression functions for gzip compression.', url: 'https://www.php.net/manual/en/book.zlib.php' },

                // Caching and Performance
                'opcache': { package: 'php-opcache', desc: 'OPcache improves PHP performance by caching precompiled script bytecode.', url: 'https://www.php.net/manual/en/book.opcache.php' },
                'Zend OPcache': { package: 'php-opcache', desc: 'OPcache improves PHP performance by caching precompiled script bytecode.', url: 'https://www.php.net/manual/en/book.opcache.php' },
                'apcu': { package: 'php-apcu', desc: 'APCu provides userland caching for PHP applications.', url: 'https://www.php.net/manual/en/book.apcu.php' },
                'apc': { package: 'php-apc', desc: 'Alternative PHP Cache for opcode and user data caching.', url: 'https://www.php.net/manual/en/book.apc.php' },
                'redis': { package: 'php-redis', desc: 'Redis client extension for in-memory data storage.', url: 'https://github.com/phpredis/phpredis' },
                'memcache': { package: 'php-memcache', desc: 'Memcache extension for distributed memory caching.', url: 'https://www.php.net/manual/en/book.memcache.php' },
                'memcached': { package: 'php-memcached', desc: 'Memcached extension for distributed memory object caching.', url: 'https://www.php.net/manual/en/book.memcached.php' },

                // XML and Web Services
                'soap': { package: 'php-soap', desc: 'SOAP client and server for web services.', url: 'https://www.php.net/manual/en/book.soap.php' },
                'xmlrpc': { package: 'php-xmlrpc', desc: 'XML-RPC client and server implementation.', url: 'https://www.php.net/manual/en/book.xmlrpc.php' },
                'xsl': { package: 'php-xsl', desc: 'XSL extension for XML transformations.', url: 'https://www.php.net/manual/en/book.xsl.php' },
                'tidy': { package: 'php-tidy', desc: 'Tidy HTML cleaning and repair library.', url: 'https://www.php.net/manual/en/book.tidy.php' },

                // Network and Communication
                'sockets': { package: 'php-sockets', desc: 'Low-level socket communication functions.', url: 'https://www.php.net/manual/en/book.sockets.php' },
                'ftp': { package: 'php-ftp', desc: 'FTP client functions for file transfer.', url: 'https://www.php.net/manual/en/book.ftp.php' },
                'ldap': { package: 'php-ldap', desc: 'Lightweight Directory Access Protocol extension.', url: 'https://www.php.net/manual/en/book.ldap.php' },
                'imap': { package: 'php-imap', desc: 'IMAP, POP3 and NNTP email protocol functions.', url: 'https://www.php.net/manual/en/book.imap.php' },
                'snmp': { package: 'php-snmp', desc: 'Simple Network Management Protocol extension.', url: 'https://www.php.net/manual/en/book.snmp.php' },

                // Security and Cryptography
                'sodium': { package: 'php-sodium', desc: 'Modern cryptography library for encryption.', url: 'https://www.php.net/manual/en/book.sodium.php' },
                'mcrypt': { package: 'php-mcrypt', desc: 'Mcrypt encryption library (deprecated in PHP 7.1).', url: 'https://www.php.net/manual/en/book.mcrypt.php' },
                'openssl': { package: 'php-openssl', desc: 'OpenSSL cryptographic functions for SSL/TLS.', url: 'https://www.php.net/manual/en/book.openssl.php' },
                'hash': { package: 'php-hash', desc: 'Hashing functions for message digests.', url: 'https://www.php.net/manual/en/book.hash.php' },

                // Development and Debugging
                'xdebug': { package: 'php-xdebug', desc: 'Debugging and profiling tool for PHP development.', url: 'https://xdebug.org/' },
                'uopz': { package: 'php-uopz', desc: 'User Operations extension for runtime manipulation.', url: 'https://www.php.net/manual/en/book.uopz.php' },
                'runkit7': { package: 'php-runkit7', desc: 'Runtime manipulation extension for PHP 7.', url: 'https://github.com/runkit7/runkit7' },
                'tideways': { package: 'php-tideways', desc: 'Application performance monitoring extension.', url: 'https://tideways.com/' },
                'xhprof': { package: 'php-xhprof', desc: 'Hierarchical profiler for PHP applications.', url: 'https://github.com/longxinH/xhprof' },
                'blackfire': { package: 'php-blackfire', desc: 'Blackfire profiler for PHP performance analysis.', url: 'https://www.blackfire.io/' },

                // System and OS
                'posix': { package: 'php-posix', desc: 'POSIX functions for system-level operations.', url: 'https://www.php.net/manual/en/book.posix.php' },
                'sysvmsg': { package: 'php-sysvmsg', desc: 'System V message queue functions.', url: 'https://www.php.net/manual/en/book.sem.php' },
                'sysvsem': { package: 'php-sysvsem', desc: 'System V semaphore functions.', url: 'https://www.php.net/manual/en/book.sem.php' },
                'sysvshm': { package: 'php-sysvshm', desc: 'System V shared memory functions.', url: 'https://www.php.net/manual/en/book.shmop.php' },
                'shmop': { package: 'php-shmop', desc: 'Shared memory operations for inter-process communication.', url: 'https://www.php.net/manual/en/book.shmop.php' },
                'pcntl': { package: 'php-pcntl', desc: 'Process control functions for Unix-like systems.', url: 'https://www.php.net/manual/en/book.pcntl.php' },
                'readline': { package: 'php-readline', desc: 'Interactive command line interface functions.', url: 'https://www.php.net/manual/en/book.readline.php' },
                'ffi': { package: 'php-ffi', desc: 'Foreign Function Interface for calling C functions.', url: 'https://www.php.net/manual/en/book.ffi.php' },

                // Math and Calculations
                'bcmath': { package: 'php-bcmath', desc: 'Arbitrary precision mathematics functions.', url: 'https://www.php.net/manual/en/book.bc.php' },
                'gmp': { package: 'php-gmp', desc: 'GNU Multiple Precision arithmetic library.', url: 'https://www.php.net/manual/en/book.gmp.php' },
                'stats': { package: 'php-stats', desc: 'Statistical functions for data analysis.', url: 'https://www.php.net/manual/en/book.stats.php' },

                // Date and Time
                'calendar': { package: 'php-calendar', desc: 'Calendar conversion functions for various calendar systems.', url: 'https://www.php.net/manual/en/book.calendar.php' },

                // Other Utilities
                'gettext': { package: 'php-gettext', desc: 'GNU gettext functions for internationalization.', url: 'https://www.php.net/manual/en/book.gettext.php' },
                'yaml': { package: 'php-yaml', desc: 'YAML data serialization format parser.', url: 'https://www.php.net/manual/en/book.yaml.php' },
                'msgpack': { package: 'php-msgpack', desc: 'MessagePack serialization format extension.', url: 'https://github.com/msgpack/msgpack-php' },
                'igbinary': { package: 'php-igbinary', desc: 'Binary serializer for faster serialization.', url: 'https://github.com/igbinary/igbinary' },
                'uuid': { package: 'php-uuid', desc: 'UUID generation and manipulation functions.', url: 'https://www.php.net/manual/en/book.uuid.php' },
                'geoip': { package: 'php-geoip', desc: 'GeoIP location identification extension.', url: 'https://www.php.net/manual/en/book.geoip.php' },
                'maxminddb': { package: 'php-maxminddb', desc: 'MaxMind DB reader for GeoIP2 databases.', url: 'https://github.com/maxmind/MaxMind-DB-Reader-php' },
                'raphf': { package: 'php-raphf', desc: 'Resource and persistent handles factory extension.', url: 'https://github.com/m6w6/php-raphf' },
                'propro': { package: 'php-propro', desc: 'Property proxy extension for object property access.', url: 'https://github.com/m6w6/php-propro' },
                'event': { package: 'php-event', desc: 'Event extension for event-driven programming.', url: 'https://www.php.net/manual/en/book.event.php' },
                'ev': { package: 'php-ev', desc: 'Libev extension for high-performance event loop.', url: 'https://github.com/m4rw3r/php-ev' },
                'parallel': { package: 'php-parallel', desc: 'Parallel processing extension for multi-threading.', url: 'https://www.php.net/manual/en/book.parallel.php' },
                'pthreads': { package: 'php-pthreads', desc: 'Threading extension for parallel execution (PHP 7 only).', url: 'https://github.com/krakjoe/pthreads' },
                'swoole': { package: 'php-swoole', desc: 'Async, concurrent networking engine for PHP.', url: 'https://www.swoole.co.uk/' },
                'amqp': { package: 'php-amqp', desc: 'AMQP extension for message queue systems like RabbitMQ.', url: 'https://www.php.net/manual/en/book.amqp.php' },
                'rdkafka': { package: 'php-rdkafka', desc: 'Kafka client extension for Apache Kafka messaging.', url: 'https://github.com/arnaud-lb/php-rdkafka' },
                'cassandra': { package: 'php-cassandra', desc: 'Apache Cassandra database driver extension.', url: 'https://github.com/datastax/php-driver' },
                'couchbase': { package: 'php-couchbase', desc: 'Couchbase NoSQL database extension.', url: 'https://docs.couchbase.com/php-sdk/current/hello-world/start-using-sdk.html' },
                'elasticsearch': { package: 'php-elasticsearch', desc: 'Elasticsearch client extension for search.', url: 'https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/index.html' },
                'solr': { package: 'php-solr', desc: 'Apache Solr search engine client extension.', url: 'https://www.php.net/manual/en/book.solr.php' },
                'v8js': { package: 'php-v8js', desc: 'V8 JavaScript engine integration for PHP.', url: 'https://github.com/phpv8/v8js' },
                'lua': { package: 'php-lua', desc: 'Lua scripting language integration for PHP.', url: 'https://github.com/laruence/php-lua' },
                'rrd': { package: 'php-rrd', desc: 'Round Robin Database extension for time-series data.', url: 'https://www.php.net/manual/en/book.rrd.php' },
                'stomp': { package: 'php-stomp', desc: 'STOMP protocol extension for message brokers.', url: 'https://www.php.net/manual/en/book.stomp.php' },
                'zmq': { package: 'php-zmq', desc: 'ZeroMQ messaging library extension.', url: 'https://github.com/mkoppanen/php-zmq' },
                'gearman': { package: 'php-gearman', desc: 'Gearman job server client extension.', url: 'https://www.php.net/manual/en/book.gearman.php' },
                'inotify': { package: 'php-inotify', desc: 'Linux inotify extension for file system monitoring.', url: 'https://www.php.net/manual/en/book.inotify.php' },
                'fann': { package: 'php-fann', desc: 'Fast Artificial Neural Network library extension.', url: 'https://www.php.net/manual/en/book.fann.php' },
                'pdflib': { package: 'php-pdflib', desc: 'PDFlib extension for PDF generation and manipulation.', url: 'https://www.php.net/manual/en/book.pdf.php' },
                'wkhtmltox': { package: 'php-wkhtmltox', desc: 'wkhtmltopdf extension for HTML to PDF conversion.', url: 'https://github.com/mreiferson/php-wkhtmltox' },
                'gmagick': { package: 'php-gmagick', desc: 'GraphicsMagick extension for image processing.', url: 'https://www.php.net/manual/en/book.gmagick.php' },
                'vips': { package: 'php-vips', desc: 'VIPS image processing library extension.', url: 'https://github.com/libvips/php-vips-ext' },
                'spx': { package: 'php-spx', desc: 'SPX profiler extension for PHP performance analysis.', url: 'https://github.com/NoiseByNorthwest/php-spx' },
            },

            isNonDefaultExtension(extName) {
                if (!extName) return false;
                const name = extName.toLowerCase();
                return !this.defaultExtensions.some(def => def.toLowerCase() === name);
            },

            getExtensionInfo(extName) {
                if (!extName) return null;
                const name = extName.toLowerCase();
                // Try exact match first
                if (this.extensionInfo[extName]) return this.extensionInfo[extName];
                // Try case-insensitive match
                for (const [key, value] of Object.entries(this.extensionInfo)) {
                    if (key.toLowerCase() === name) return value;
                }
                // Return default info if not found
                return { package: `php-${name}`, desc: 'PHP extension that requires installation.', url: `https://www.php.net/manual/en/book.${name}.php` };
            },

            expandedExtensions: new Set(),
            toggleExtensionInfo(extName) {
                if (this.expandedExtensions.has(extName)) {
                    this.expandedExtensions.delete(extName);
                } else {
                    this.expandedExtensions.add(extName);
                }
            },
            isExtensionExpanded(extName) {
                return this.expandedExtensions.has(extName);
            },

            async init() { await this.reload(); },

            async reload() {
                this.loading = true;
                this.error = null;

                try {
                    const url = new URL(this.apiUrl, window.location.origin);
                    if (this.token) url.searchParams.set('token', this.token);

                    const res = await fetch(url.toString(), {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store',
                    });

                    if (!res.ok) {
                        const text = await res.text().catch(() => '');
                        throw new Error(`HTTP ${res.status} ${res.statusText}\n\n${text}`.trim());
                    }

                    const json = await res.json();
                    this.raw = json;

                    const phpObj = json?.php ?? null;      // newer backend shape (if you use it)
                    const core   = json?.Core ?? null;     // your current phpinfo-like JSON
                    const op     = json?.['Zend OPcache'] ?? null;
                    const curl   = json?.curl ?? null;
                    const openssl= json?.openssl ?? null;

                    this.common.generated_at = json?._meta?.generated_at ?? json?.generated_at ?? null;

                    this.common.php_version =
                        phpObj?.version ??
                        json?._meta?.php_version ??
                        core?.['PHP Version'] ??
                        null;

                    this.common.sapi =
                        phpObj?.sapi ??
                        json?._meta?.sapi ??
                        core?.['Server API'] ??
                        null;

                    this.common.binary =
                        phpObj?.binary ??
                        json?._meta?.binary ??
                        null;

                    this.common.os =
                        phpObj?.os ??
                        json?._meta?.os ??
                        core?.['System'] ??
                        null;

                    this.common.timezone =
                        phpObj?.date_timezone ??
                        json?._meta?.timezone ??
                        core?.['date.timezone']?.local ??
                        core?.['date.timezone'] ??
                        null;

                    // Counts
                    this.common.extensions_count =
                        Array.isArray(json?.loaded_extensions) ? json.loaded_extensions.length :
                            (phpObj?.extensions ? Object.keys(phpObj.extensions).length : null);

                    // INI paths (only available in your newer backend shape)
                    this.common.ini_loaded_file =
                        phpObj?.ini?.loaded_file ??
                        json?._meta?.ini_loaded ??
                        null;

                    this.common.ini_scanned_files =
                        phpObj?.ini?.scanned_files ??
                        json?._meta?.ini_scanned ??
                        null;

                    // Limits (prefer newer shape, fallback to Core directives, then _meta)
                    this.common.memory_limit =
                        phpObj?.memory_limit ??
                        json?._meta?.memory_limit ??
                        core?.['memory_limit']?.local ??
                        core?.['memory_limit'] ??
                        null;

                    this.common.upload_max_filesize =
                        phpObj?.upload_max_filesize ??
                        json?._meta?.upload_max_filesize ??
                        core?.['upload_max_filesize']?.local ??
                        core?.['upload_max_filesize'] ??
                        null;

                    this.common.post_max_size =
                        phpObj?.post_max_size ??
                        json?._meta?.post_max_size ??
                        core?.['post_max_size']?.local ??
                        core?.['post_max_size'] ??
                        null;

                    this.common.max_execution_time =
                        phpObj?.max_execution_time ??
                        json?._meta?.max_execution_time ??
                        core?.['max_execution_time']?.local ??
                        core?.['max_execution_time'] ??
                        null;

                    this.common.extension_dir =
                        core?.['extension_dir']?.local ??
                        core?.['extension_dir'] ??
                        null;

                    // OPcache (from Zend OPcache section)
                    this.common.opcache_status =
                        op?.['Opcode Caching'] ??
                        null;

                    this.common.opcache_jit =
                        op?.['JIT'] ??
                        op?.['opcache.jit']?.local ??
                        null;

                    this.common.opcache_hits =
                        op?.['Cache hits'] ??
                        null;

                    // cURL (from curl section)
                    this.common.curl_version =
                        curl?.['cURL Information'] ??
                        curl?.['cURL support'] ? 'enabled' : null;

                    this.common.curl_ssl_version =
                        curl?.['SSL Version'] ??
                        null;

                    // OpenSSL (from openssl section)
                    this.common.openssl_library =
                        openssl?.['OpenSSL Library Version'] ??
                        null;

                    // Build extensions list
                    this.extensions = this.buildExtensions(json);

                    // Store loaded extensions
                    this.loadedExtensions = Array.isArray(json?.loaded_extensions)
                        ? json.loaded_extensions
                        : [];

                    // Keep open item sane
                    this.openExt = this.extensions[0]?.name ?? null;
                    this.expandedAll = false;

                } catch (e) {
                    this.error = String(e?.message ?? e);
                } finally {
                    this.loading = false;
                }
            },

            toggleExtension(name) {
                if (this.expandedAll) this.expandedAll = false;
                this.openExt = (this.openExt === name) ? null : name;
            },

            expandAll() { this.expandedAll = true; },
            collapseAll() { this.expandedAll = false; this.openExt = null; },

            get filteredLoadedExtensions() {
                const q = (this.extensionQuery || '').trim().toLowerCase();
                if (!this.loadedExtensions || !Array.isArray(this.loadedExtensions)) return [];

                if (!q) return this.loadedExtensions.sort();

                return this.loadedExtensions
                    .filter(ext => ext.toLowerCase().includes(q))
                    .sort();
            },

            get filteredExtensions() {
                const q = (this.query || '').trim().toLowerCase();

                const list = this.extensions.map(ext => {
                    if (!q) return { ...ext, matches: 0 };

                    let matches = 0;
                    if (ext.name.toLowerCase().includes(q)) matches++;

                    for (const r of ext.rows) {
                        const k = (r.k ?? '').toLowerCase();
                        if (k.includes(q)) { matches++; continue; }

                        if (r.type === 'scalar') {
                            const v = String(r.v ?? '').toLowerCase();
                            if (v.includes(q)) matches++;
                        } else {
                            const v = JSON.stringify(r.v ?? '').toLowerCase();
                            if (v.includes(q)) matches++;
                        }
                    }

                    return { ...ext, matches };
                });

                if (!q) return list;

                return list
                    .filter(x => x.matches > 0)
                    .sort((a, b) => b.matches - a.matches || a.name.localeCompare(b.name));
            },

            buildExtensions(json) {
                const skipKeys = new Set(['loaded_extensions']);
                const out = [];

                for (const [name, raw] of Object.entries(json || {})) {
                    if (skipKeys.has(name)) continue;

                    const isObj = raw && typeof raw === 'object' && !Array.isArray(raw);
                    const isArr = Array.isArray(raw);

                    if (!isObj && !isArr) continue;

                    if (isArr && raw.length === 0 && (name === 'Additional Modules' || name === 'PHP License')) {
                        continue;
                    }

                    const version = this.detectVersion(raw);
                    const rows = this.objectToRows(raw);
                    const typesSummary = this.summarizeRowTypes(rows);

                    out.push({ name, version, raw, rows, count: rows.length, typesSummary });
                }

                // If loaded_extensions exists, follow it for ordering
                const order = Array.isArray(json?.loaded_extensions) ? json.loaded_extensions : null;
                if (order) {
                    const orderIndex = new Map(order.map((n, i) => [n, i]));
                    out.sort((a, b) => {
                        const ai = orderIndex.has(a.name) ? orderIndex.get(a.name) : 999999;
                        const bi = orderIndex.has(b.name) ? orderIndex.get(b.name) : 999999;
                        if (ai !== bi) return ai - bi;
                        return a.name.localeCompare(b.name);
                    });
                } else {
                    out.sort((a, b) => a.name.localeCompare(b.name));
                }

                return out;
            },

            detectVersion(raw) {
                const candidates = [
                    'PHP Version',
                    'Version',
                    'cURL Information',
                    'OpenSSL Library Version',
                    'Redis Version',
                    'MongoDB extension version',
                    'imagick module version',
                    'ICU version',
                    'SQLite Library',
                    'PostgreSQL(libpq) Version',
                    'ExtensionVer',
                    'Zip version',
                ];

                if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
                    for (const k of candidates) {
                        if (k in raw && typeof raw[k] === 'string') return raw[k];
                    }
                }
                return null;
            },

            objectToRows(raw) {
                const rows = [];

                if (Array.isArray(raw)) {
                    rows.push({ k: '(array)', v: raw, type: 'json' });
                    return rows;
                }

                for (const [k, v] of Object.entries(raw || {})) {
                    if (v && typeof v === 'object' && !Array.isArray(v)) {
                        const keys = Object.keys(v);
                        const isDirective = keys.length <= 5 && ('local' in v || 'master' in v);

                        if (isDirective) rows.push({ k, v, type: 'directive' });
                        else rows.push({ k, v, type: 'json' });

                    } else if (Array.isArray(v)) {
                        rows.push({ k, v, type: 'json' });
                    } else {
                        rows.push({ k, v: (v === null ? 'null' : String(v)), type: 'scalar' });
                    }
                }

                return rows;
            },

            summarizeRowTypes(rows) {
                let scalar = 0, directive = 0, json = 0;
                for (const r of rows) {
                    if (r.type === 'scalar') scalar++;
                    else if (r.type === 'directive') directive++;
                    else json++;
                }
                const parts = [];
                if (scalar) parts.push(`${scalar} scalar`);
                if (directive) parts.push(`${directive} local/master`);
                if (json) parts.push(`${json} json`);
                return parts.join(' • ') || '—';
            },

            async copyJSON(obj, event) {
                try {
                    const text = JSON.stringify(obj ?? {}, null, 2);
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(text);
                        // Show brief feedback
                        const btn = event?.target?.closest('button');
                        if (btn) {
                            const originalText = btn.textContent;
                            btn.textContent = 'Copied!';
                            setTimeout(() => {
                                btn.textContent = originalText;
                            }, 2000);
                        }
                    } else {
                        // Fallback for older browsers
                        const textArea = document.createElement('textarea');
                        textArea.value = text;
                        textArea.style.position = 'fixed';
                        textArea.style.opacity = '0';
                        document.body.appendChild(textArea);
                        textArea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textArea);
                        const btn = event?.target?.closest('button');
                        if (btn) {
                            const originalText = btn.textContent;
                            btn.textContent = 'Copied!';
                            setTimeout(() => {
                                btn.textContent = originalText;
                            }, 2000);
                        }
                    }
                } catch (err) {
                    console.error('Failed to copy:', err);
                }
            },

            downloadJSON(name, obj) {
                const blob = new Blob([JSON.stringify(obj ?? {}, null, 2)], { type: 'application/json' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `${name}.json`;
                a.click();
                URL.revokeObjectURL(a.href);
            },

            initTheme() {
                const saved = localStorage.getItem('theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                this.isDark = saved ? saved === 'dark' : prefersDark;
                this.applyTheme();
            },

            toggleTheme() {
                this.isDark = !this.isDark;
                this.applyTheme();
                localStorage.setItem('theme', this.isDark ? 'dark' : 'light');
            },

            applyTheme() {
                const html = document.documentElement;
                if (this.isDark) {
                    html.classList.add('dark');
                } else {
                    html.classList.remove('dark');
                }
                // Force a re-render to ensure Tailwind picks up the change
                html.style.display = 'none';
                html.offsetHeight; // Trigger reflow
                html.style.display = '';
            },
        }
    }
</script>
</body>
</html>
