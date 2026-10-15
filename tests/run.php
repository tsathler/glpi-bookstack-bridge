<?php

declare(strict_types=1);

require __DIR__ . '/../src/Api/BookStackException.php';
require __DIR__ . '/../src/Security/BaseUrlPolicy.php';
require __DIR__ . '/../src/ConfigSubmission.php';
require __DIR__ . '/../src/Api/BookStackClient.php';

use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;
use GlpiPlugin\Bookstackbridge\ConfigSubmission;
use GlpiPlugin\Bookstackbridge\Menu;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;

define('UPDATE', 2);

class CommonGLPI
{
}

class Session
{
    public static bool $canConfigure = true;

    public static function haveRight(string $right, int $level): bool
    {
        return self::$canConfigure && $right === 'config' && $level === UPDATE;
    }
}

function __($message, $domain = null): string
{
    return $message;
}

require __DIR__ . '/../src/Menu.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$template = file_get_contents(__DIR__ . '/../templates/config.html.twig');
$assert(is_string($template), 'Configuration Twig template is readable.');
$assert(
    preg_match('/\{\%\s*extends\s+["\']layout\/page_without_tabs\.html\.twig["\']\s*\%\}/', $template) === 1,
    'Configuration template extends the GLPI 11 page layout.'
);
$assert(
    str_contains($template, '{% block content_title %}') && str_contains($template, '{% block content_body %}'),
    'Configuration template overrides the GLPI page title and body blocks.'
);
$routeResources = [
    '/plugins/bookstackbridge/configure',
    '/plugins/bookstackbridge/test',
    '/plugins/bookstackbridge/search',
];
foreach ($routeResources as $resource) {
    $assert(
        str_contains($template, "path('{$resource}')"),
        "Form target {$resource} uses GLPI's prefixed resource path."
    );
}
$rootDoc = '/glpi';
$getPrefixedUrl = static function (string $resource) use ($rootDoc): string {
    $prefix = $rootDoc;
    if (!str_starts_with($resource, '/')) {
        $prefix .= '/';
    }
    return $prefix . $resource;
};
$renderedForms = preg_replace_callback(
    '/\{\{\s*path\(\'([^\']+)\'\)\s*\}\}/',
    static fn (array $matches): string => htmlspecialchars($getPrefixedUrl($matches[1]), ENT_QUOTES, 'UTF-8'),
    $template
);
$assert(is_string($renderedForms), 'Form actions render through the simulated GLPI path fallback.');
preg_match_all('/<form\b[^>]*\baction="([^"]*)"/', $renderedForms, $formMatches);
$assert(
    $formMatches[1] === [
        '/glpi/plugins/bookstackbridge/configure',
        '/glpi/plugins/bookstackbridge/test',
        '/glpi/plugins/bookstackbridge/search',
    ],
    'Rendered form actions include GLPI root_doc and each plugin endpoint.'
);
$assert(
    str_contains($template, 'name="allowed_private_ips"') && str_contains($template, 'maxlength="4096"'),
    'Configuration form exposes a bounded private-IP allowlist field.'
);
$assert(
    preg_match('/name="api_token_id"[^>]*required/', $template) !== 1
        && preg_match('/name="api_token"[^>]*required/', $template) !== 1,
    'API token fields are optional during network configuration.'
);
$assert(
    str_contains($template, 'integrationReady')
        && str_contains($template, 'networkConfigured')
        && str_contains($template, 'credentialsComplete'),
    'Configuration page displays network and integration readiness states.'
);
$assert(
    !str_contains($template, 'value="{{ config.api_token_id')
        && !str_contains($template, 'value="{{ config.api_token|'),
    'Stored credentials are never rendered into form values.'
);
$assert(substr_count($template, 'csrf_token()') === 3, 'All three POST forms retain CSRF tokens.');

$glpiRoot = getenv('GLPI_ROOT');
if (is_string($glpiRoot) && $glpiRoot !== '') {
    $layoutPath = $glpiRoot . '/templates/layout/page_without_tabs.html.twig';
    $autoloadPath = $glpiRoot . '/vendor/autoload.php';
    $assert(is_file($layoutPath), 'GLPI_ROOT contains the page layout used by the plugin.');
    $assert(is_file($autoloadPath), 'GLPI_ROOT contains the Composer autoloader needed for Twig rendering.');
    require_once $autoloadPath;

    $loader = new Twig\Loader\FilesystemLoader($glpiRoot . '/templates');
    $loader->addPath(__DIR__ . '/../templates', 'bookstackbridge');
    $twig = new Twig\Environment($loader, ['autoescape' => 'html']);
    $twig->addFunction(new Twig\TwigFunction('get_current_interface', static fn (): string => 'central'));
    $twig->addFunction(new Twig\TwigFunction('call', static fn (...$arguments): null => null));
    $twig->addFunction(new Twig\TwigFunction('path', static function (string $resource): string {
        $rootDoc = '/glpi';
        return $rootDoc . $resource;
    }));
    $twig->addFunction(new Twig\TwigFunction('csrf_token', static fn (): string => 'test-token'));
    $twig->addFunction(new Twig\TwigFunction('__', static fn (string $message, ?string $domain = null): string => $message));
    $rendered = $twig->render('@bookstackbridge/config.html.twig', [
        'config' => ['base_url' => '', 'api_token_id' => ''],
        'result' => null,
        'error' => null,
        'notice' => null,
        'query' => '',
        'networkConfigured' => false,
        'credentialsComplete' => false,
        'integrationReady' => false,
    ]);
    $assert(str_contains($rendered, 'BookStack connection'), 'Configuration page renders through the real GLPI layout.');
    $assert(str_contains($rendered, 'BookStack HTTPS URL'), 'Rendered page includes the configuration form.');
    foreach (['configure', 'test', 'search'] as $routePath) {
        $assert(
            str_contains($rendered, "action=\"/glpi/plugins/bookstackbridge/{$routePath}\""),
            "Rendered form uses the root_doc-prefixed plugin destination for {$routePath}."
        );
    }
}

$menu = Menu::getMenuContent();
$assert($menu['title'] === 'BookStack knowledge base', 'Menu builds successfully with GLPI global classes.');
$assert($menu['page'] === '/plugins/bookstackbridge/', 'Menu points to the plugin route.');
Session::$canConfigure = false;
$assert(Menu::getMenuContent() === [], 'Menu stays hidden without configuration permission.');
Session::$canConfigure = true;

$initialConfig = ConfigSubmission::merge([], 'https://1.1.1.1', ['10.20.30.40'], '', '');
$assert(
    $initialConfig['base_url'] === 'https://1.1.1.1'
        && $initialConfig['allowed_private_ips'] === '10.20.30.40'
        && $initialConfig['api_token_id'] === ''
        && $initialConfig['api_token'] === '',
    'Saves network configuration with no API credentials during initial setup.'
);
$existingCredentials = ['api_token_id' => 'existing-id', 'api_token' => 'existing-secret'];
$urlUpdate = ConfigSubmission::merge($existingCredentials, 'https://1.1.1.1/path', [], '', '');
$assert(
    $urlUpdate['api_token_id'] === 'existing-id' && $urlUpdate['api_token'] === 'existing-secret',
    'Updating network settings with blank credential fields preserves stored credentials.'
);
$tokenIdUpdate = ConfigSubmission::merge($existingCredentials, 'https://1.1.1.1', [], 'replacement-id', '');
$assert(
    $tokenIdUpdate['api_token_id'] === 'replacement-id' && $tokenIdUpdate['api_token'] === 'existing-secret',
    'Updating only the token ID preserves the stored secret.'
);
$secretUpdate = ConfigSubmission::merge($existingCredentials, 'https://1.1.1.1', [], '', 'replacement-secret');
$assert(
    $secretUpdate['api_token_id'] === 'existing-id' && $secretUpdate['api_token'] === 'replacement-secret',
    'Updating only the token secret preserves the stored token ID.'
);
try {
    new BookStackClient('https://1.1.1.1', '', '', static fn () => ['status' => 200, 'body' => '{}']);
    $assert(false, 'API client must require complete credentials for API operations.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'token'), 'API operations reject missing credentials.');
}

$client = new BookStackClient('https://1.1.1.1', 'id', 'secret', static function (string $url, array $headers): array {
    if (!str_contains($url, '/api/search?query=network+setup&count=20&page=2')) {
        throw new RuntimeException('Search must use BookStack search paging parameters.');
    }
    if (!in_array('Authorization: Token id:secret', $headers, true)) {
        throw new RuntimeException('BookStack token header is missing.');
    }
    return ['status' => 200, 'body' => json_encode(['data' => [['name' => 'Guide']], 'total' => 12])];
});
$result = $client->search('network setup', 2);
$assert($result['total'] === 12 && $result['data'][0]['name'] === 'Guide', 'Parses a valid search response.');

try {
        new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => 401, 'body' => '{}'])->search('x');
    $assert(false, '401 should fail.');
} catch (BookStackException $e) {
    $assert($e->statusCode === 401 && str_contains($e->getMessage(), 'token'), 'Maps authentication failures.');
}

foreach ([403, 429, 500] as $status) {
    try {
        (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => $status, 'body' => '{}']))->search('x');
        $assert(false, "HTTP {$status} should fail.");
    } catch (BookStackException $e) {
        $assert($e->statusCode === $status && $e->getMessage() !== '', "Maps HTTP {$status} failures.");
    }
}

try {
    (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => 200, 'body' => '<html>']))->search('x');
    $assert(false, 'Invalid JSON should fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'JSON'), 'Handles malformed JSON.');
}

try {
    (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => 200, 'body' => '{"unexpected":true}']))->search('x');
    $assert(false, 'Unexpected response format should fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'format'), 'Handles unexpected response shapes.');
}

try {
    $client->search('');
    $assert(false, 'Empty query should fail.');
} catch (BookStackException) {
    $assert(true, 'Rejects empty search queries.');
}

try {
    (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => throw new RuntimeException('transport failure')))->search('x');
    $assert(false, 'Transport errors should fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'connect'), 'Maps transport failures without leaking details.');
}

try {
    new BookStackClient('http://docs.example.org', 'id', 'secret');
    $assert(false, 'HTTP should fail.');
} catch (BookStackException) {
    $assert(true, 'Rejects non-HTTPS URLs.');
}

try {
    BaseUrlPolicy::validate('https://127.0.0.1');
    $assert(false, 'Private hosts should fail.');
} catch (BookStackException) {
    $assert(true, 'Rejects private IP addresses.');
}

$assert(BaseUrlPolicy::validate('https://1.1.1.1') === 'https://1.1.1.1', 'Accepts a public HTTPS IP address.');
$assert(
    BaseUrlPolicy::validate('https://10.20.30.40', ['10.20.30.40']) === 'https://10.20.30.40',
    'Accepts an exact private IP explicitly present in the allowlist.'
);
$assert(
    BaseUrlPolicy::validate('https://[fd12:3456::10]', ['fd12:3456::10']) === 'https://[fd12:3456::10]',
    'Accepts an exact authorized private IPv6 destination.'
);

$expectPolicyFailure = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (BookStackException) {
        $assert(true, $message);
    }
};
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://10.20.30.40'),
    'Rejects a private address without allowlisting.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::parsePrivateIpAllowlist('10.20.30.0/24'),
    'Rejects subnet ranges in the private allowlist.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::parsePrivateIpAllowlist('127.0.0.1'),
    'Does not allow loopback destinations, even through the allowlist.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::parsePrivateIpAllowlist('169.254.10.20'),
    'Does not allow link-local destinations, even through the allowlist.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://169.254.169.254', ['10.20.30.40']),
    'Blocks metadata destinations.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://1.1.1.1:444'),
    'Rejects non-standard HTTPS ports.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://1.1.1.1:65536'),
    'Rejects out-of-range ports.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://localhost', ['10.20.30.40']),
    'Rejects local hostnames.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://0x7f.0.0.1', ['10.20.30.40']),
    'Rejects malformed numeric-style hostnames before DNS lookup.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://192.0.2.10', ['10.20.30.40']),
    'Rejects documentation and reserved IP ranges.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://192.88.99.1', ['10.20.30.40']),
    'Rejects deprecated special-purpose IPv4 ranges.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::validate('https://[3fff::1]', ['fd12:3456::10']),
    'Rejects special-purpose IPv6 documentation ranges.'
);
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::parsePrivateIpAllowlist('224.0.0.1'),
    'Does not allow multicast destinations.'
);

$multiRecord = BaseUrlPolicy::resolve(
    'https://docs.example.test',
    '',
    static fn (string $host): array => ['1.1.1.1', '8.8.8.8']
);
$assert($multiRecord['ip'] === '1.1.1.1', 'Checks every A/AAAA record and selects a validated address.');
$privateHostname = BaseUrlPolicy::validate(
    'https://bookstack.example.test',
    ['10.20.30.40'],
    static fn (string $host): array => ['10.20.30.40']
);
$assert(
    $privateHostname === 'https://bookstack.example.test',
    'Allows a DNS hostname only when its private destination IP is explicitly authorized.'
);
$multiAddress = BaseUrlPolicy::resolve(
    'https://docs.example.test',
    '',
    static fn (string $host): array => ['1.1.1.1', '2606:4700:4700::1111']
);
$assert($multiAddress['ip'] === '1.1.1.1', 'Validates mixed IPv4 and IPv6 DNS answers before selecting one.');
$expectPolicyFailure(
    static fn () => BaseUrlPolicy::resolve(
        'https://docs.example.test',
        ['10.20.30.40'],
        static fn (string $host): array => ['1.1.1.1', '10.20.30.41']
    ),
    'Rejects a hostname if any private DNS result is not explicitly allowed.'
);

$resolveCalls = 0;
$observedOptions = [];
$pinnedClient = new BookStackClient(
    'https://kb.example.test',
    'id',
    'secret',
    static function (string $url, array $headers, array $options) use (&$observedOptions): array {
        $observedOptions = $options;
        return ['status' => 200, 'body' => '{"data":[],"total":0}'];
    },
    ['10.20.30.40'],
    static function (string $host) use (&$resolveCalls): array {
        $resolveCalls++;
        return $resolveCalls === 1 ? ['10.20.30.40'] : ['169.254.169.254'];
    }
);
$pinnedClient->search('safe query');
$assert($resolveCalls === 1, 'DNS is resolved once per client and is not re-resolved during a request.');
$assert($observedOptions['resolve'] === 'kb.example.test:443:10.20.30.40', 'HTTP transport pins the validated hostname to its validated IP.');
$assert(
    $observedOptions['verify_peer'] === true && $observedOptions['verify_host'] === 2
        && $observedOptions['follow_redirects'] === false && $observedOptions['proxy'] === false,
    'Pinned requests retain full TLS verification, disable redirects, and bypass unpinned proxies.'
);

$expectPolicyFailure(
    static fn () => (new BookStackClient(
        'https://kb.example.test',
        'id',
        'secret',
        null,
        ['10.20.30.40'],
        static fn (string $host): array => ['10.20.30.41']
    ))->search('safe query'),
    'Rejects a changed DNS answer unless the exact new IP is explicitly authorized.'
);

$expectPolicyFailure(
    static fn () => (new BookStackClient(
        'https://1.1.1.1',
        'id',
        'secret',
        static fn (string $url, array $headers, array $options): array => ['status' => 302, 'body' => '']
    ))->search('safe query'),
    'Rejects redirects instead of following them.'
);

try {
    (new BookStackClient(
        'https://1.1.1.1',
        'id',
        'secret',
        static function (string $url, array $headers, array $options) use (&$observedOptions): never {
            $observedOptions = $options;
            throw new BookStackException('BookStack TLS certificate validation failed. Check trust chain and certificate SAN.');
        }
    ))->search('safe query');
    $assert(false, 'Simulated TLS transport failure must fail safely.');
} catch (BookStackException $e) {
    $assert(
        str_contains($e->getMessage(), 'certificate') && !str_contains($e->getMessage(), 'private host'),
        'Simulated certificate rejection is reported without exposing transport details.'
    );
}
$assert(
    $observedOptions['verify_peer'] === true && $observedOptions['verify_host'] === 2,
    'Certificate errors cannot be bypassed by disabling TLS verification.'
);

$allowlist = BaseUrlPolicy::parsePrivateIpAllowlist("10.20.30.40\nfd12:3456::10\n");
$storedAllowlist = implode("\n", $allowlist);
$assert(
    BaseUrlPolicy::parsePrivateIpAllowlist($storedAllowlist) === $allowlist,
    'Allowlist values round-trip through the same normalized representation used for GLPI configuration.'
);
$hook = file_get_contents(__DIR__ . '/../hook.php');
$controller = file_get_contents(__DIR__ . '/../src/Controller/ConfigController.php');
$submission = file_get_contents(__DIR__ . '/../src/ConfigSubmission.php');
$assert(
    is_string($hook) && str_contains($hook, "'allowed_private_ips' => ''")
        && is_string($controller) && str_contains($controller, 'ConfigSubmission::merge(')
        && is_string($submission) && str_contains($submission, "'allowed_private_ips' => implode(\"\\n\", \$privateIpAllowlist)"),
    'Allowlist has an installation default and validated configuration is persisted through the merge helper.'
);
$assert(
    is_string($controller) && substr_count($controller, "methods: ['GET', 'POST']") === 3
        && substr_count($controller, '$this->requirePost($request);') === 3,
    'POST form routes retain strict method checks while remaining discoverable on GLPI 11.0.4.'
);

echo "OK ({$assertions} assertions)\n";
