<?php
declare(strict_types=1);

session_start();

/*
 * Search Aggregator Demo (safer classroom version)
 *
 * Changes from the earlier version:
 * - Uses POST instead of GET for submitted form data
 * - Does not place the API key in the URL
 * - Does not repopulate the API key back into the form after submit
 * - Adds CSRF protection for the form submission
 * - Warns when the page is not being served over HTTPS
 *
 * Important:
 * This is only "more appropriate" for handling sensitive input.
 * It is NOT truly secure unless the site itself is served over valid HTTPS.
 */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return false;
}

function normalize_domain(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return '';
    }

    $host = strtolower((string)$host);
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function parse_comma_separated(string $text): array {
    $parts = array_map('trim', explode(',', $text));
    return array_values(array_filter($parts, fn($item) => $item !== ''));
}

function build_query(string $baseQuery, array $sites, array $exactMatches): string {
    $parts = [];

    if (trim($baseQuery) !== '') {
        $parts[] = trim($baseQuery);
    }

    foreach ($exactMatches as $phrase) {
        $phrase = trim((string)$phrase);
        if ($phrase === '') {
            continue;
        }

        if (str_starts_with($phrase, '"') && str_ends_with($phrase, '"')) {
            $parts[] = $phrase;
        } else {
            $parts[] = '"' . $phrase . '"';
        }
    }

    foreach ($sites as $site) {
        $site = strtolower(trim((string)$site));
        $site = preg_replace('#^https?://#', '', $site);
        $site = trim((string)$site, '/');

        if ($site !== '') {
            $parts[] = 'site:' . $site;
        }
    }

    return trim(implode(' ', $parts));
}

function serpapi_request(string $apiKey, string $query, int $start, string $safe): array {
    $endpoint = 'https://serpapi.com/search.json';
    $params = http_build_query([
        'engine' => 'google',
        'q' => $query,
        'api_key' => $apiKey,
        'start' => $start,
        'safe' => $safe,
    ]);

    $ch = curl_init($endpoint . '?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'SearchAggregatorDemo/1.1',
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        throw new RuntimeException('SerpApi returned HTTP ' . $status);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON returned by SerpApi.');
    }

    return $data;
}

$httpsWarning = !is_https_request();
$baseQuery = '';
$siteInput = '';
$exactInput = '';
$pages = 1;
$safe = 'off';
$results = [];
$error = '';
$finalQuery = '';
$ranSearch = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ranSearch = true;

    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $error = 'Invalid form submission. Please refresh and try again.';
    } else {
        $apiKey = isset($_POST['api_key']) ? trim((string)$_POST['api_key']) : '';
        $baseQuery = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
        $siteInput = isset($_POST['sites']) ? trim((string)$_POST['sites']) : '';
        $exactInput = isset($_POST['exact']) ? trim((string)$_POST['exact']) : '';
        $pages = isset($_POST['pages']) ? max(1, min(10, (int)$_POST['pages'])) : 1;
        $safe = isset($_POST['safe']) ? trim((string)$_POST['safe']) : 'off';
        $safe = $safe === 'active' ? 'active' : 'off';

        if ($apiKey === '') {
            $error = 'Enter your SerpApi key first.';
        } else {
            $sites = parse_comma_separated($siteInput);
            $exactMatches = parse_comma_separated($exactInput);
            $finalQuery = build_query($baseQuery, $sites, $exactMatches);

            if ($finalQuery === '') {
                $error = 'Enter a base query, an exact-match phrase, or a site filter.';
            } else {
                try {
                    $seen = [];

                    for ($pageNumber = 1; $pageNumber <= $pages; $pageNumber++) {
                        $start = ($pageNumber - 1) * 10;
                        $data = serpapi_request($apiKey, $finalQuery, $start, $safe);

                        if (!empty($data['error'])) {
                            throw new RuntimeException('SerpApi error: ' . $data['error']);
                        }

                        $organic = $data['organic_results'] ?? [];
                        if (!is_array($organic)) {
                            continue;
                        }

                        foreach ($organic as $item) {
                            $url = isset($item['link']) ? trim((string)$item['link']) : '';
                            $title = isset($item['title']) ? trim((string)$item['title']) : '';
                            $position = isset($item['position']) ? (string)$item['position'] : '';
                            $domain = $url !== '' ? normalize_domain($url) : '';

                            if ($url === '' || !str_starts_with($url, 'http')) {
                                continue;
                            }

                            $key = $finalQuery . '|' . $url;
                            if (isset($seen[$key])) {
                                continue;
                            }
                            $seen[$key] = true;

                            $results[] = [
                                'query' => $finalQuery,
                                'domain' => $domain,
                                'title' => $title,
                                'url' => $url,
                                'position' => $position,
                                'page_number' => $pageNumber,
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Search Aggregator Demo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.4; }
        h1 { margin-bottom: 0.25rem; }
        .note { color: #555; margin-bottom: 1rem; }
        form { margin-bottom: 1.5rem; padding: 1rem; border: 1px solid #ccc; }
        label { display: block; margin-top: 0.75rem; font-weight: 600; }
        input, select, button { margin-top: 0.25rem; padding: 0.5rem; width: 100%; max-width: 900px; }
        button { max-width: 220px; cursor: pointer; }
        .error { background: #ffe5e5; border: 1px solid #cc6666; padding: 0.75rem; margin-bottom: 1rem; }
        .success { margin-bottom: 1rem; color: #333; }
        .final-query { background: #f5f5f5; padding: 0.75rem; margin-bottom: 1rem; border: 1px solid #ddd; }
        .warning { background: #fff4d6; border: 1px solid #d6a73a; padding: 0.75rem; margin-bottom: 1rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.5rem; vertical-align: top; text-align: left; }
        th { background: #f5f5f5; }
        a { word-break: break-word; }
        .small-note { font-size: 0.95rem; color: #555; margin-top: 0.35rem; }
    </style>
</head>
<body>
    <h1>Search Aggregator Demo</h1>
    <p class="note">Basic class demo page. Design can come later.</p>

    <?php if ($httpsWarning): ?>
        <div class="warning">
            <strong>Connection warning:</strong> This page does not appear to be running over HTTPS right now.
            Entering an API key on a non-HTTPS page is not recommended.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

        <label for="api_key">API key</label>
        <input
            type="password"
            id="api_key"
            name="api_key"
            value=""
            autocomplete="off"
            spellcheck="false"
            inputmode="text"
        >
        <p class="small-note">The key is submitted by POST and is not written back into the form after submission.</p>

        <label for="q">Base query</label>
        <input type="text" id="q" name="q" value="<?php echo htmlspecialchars($baseQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. John Smith marketing">

        <label for="sites">Optional site: filters (comma + space is fine)</label>
        <input type="text" id="sites" name="sites" value="<?php echo htmlspecialchars($siteInput, ENT_QUOTES, 'UTF-8'); ?>" placeholder="linkedin.com, umich.edu">

        <label for="exact">Optional matches exactly (comma + space is fine)</label>
        <input type="text" id="exact" name="exact" value="<?php echo htmlspecialchars($exactInput, ENT_QUOTES, 'UTF-8'); ?>" placeholder="John Smith, University of Michigan">

        <label for="pages">How many result pages</label>
        <input type="number" id="pages" name="pages" min="1" max="10" value="<?php echo (int)$pages; ?>">

        <label for="safe">SafeSearch</label>
        <select id="safe" name="safe">
            <option value="off" <?php echo $safe === 'off' ? 'selected' : ''; ?>>off</option>
            <option value="active" <?php echo $safe === 'active' ? 'selected' : ''; ?>>active</option>
        </select>

        <button type="submit">Run search</button>
    </form>

    <?php if ($finalQuery !== '' && $error === ''): ?>
        <div class="final-query">
            <strong>Final query:</strong><br>
            <?php echo htmlspecialchars($finalQuery, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($ranSearch && $error === ''): ?>
        <p class="success">Found <strong><?php echo count($results); ?></strong> unique result(s).</p>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <table>
            <thead>

                <tr>
                    <th>Title</th>
                    <th>Domain</th>
                    <th>URL</th>
                    <th>Position</th>
                    <th>Page</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['domain'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><a href="<?php echo htmlspecialchars($row['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($row['url'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?php echo htmlspecialchars((string)$row['position'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['page_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
