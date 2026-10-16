<?php

declare(strict_types=1);

require __DIR__ . '/../src/Api/BookStackException.php';
require __DIR__ . '/../src/Security/BaseUrlPolicy.php';
require __DIR__ . '/../src/ConfigurationException.php';
require __DIR__ . '/../src/ConfigRepository.php';
require __DIR__ . '/../src/ConfigSubmission.php';
require __DIR__ . '/../src/Api/BookStackClient.php';
require __DIR__ . '/../src/Service/NavigationService.php';

use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;
use GlpiPlugin\Bookstackbridge\ConfigRepository;
use GlpiPlugin\Bookstackbridge\ConfigSubmission;
use GlpiPlugin\Bookstackbridge\ConfigurationException;
use GlpiPlugin\Bookstackbridge\Menu;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;
use GlpiPlugin\Bookstackbridge\Service\NavigationService;

define('UPDATE', 2);
define('READ', 1);

class CommonGLPI
{
}

class Session
{
    public static bool $canConfigure = true;
    public static bool $canReadKnowledge = true;

    public static function haveRight(string $right, int $level): bool
    {
        return ($right === 'config' && $level === UPDATE && self::$canConfigure)
            || ($right === 'knowbase' && $level === READ && self::$canReadKnowledge);
    }
}

/** Simulates GLPI's secured config persistence contract for standalone tests. */
class GLPIKey
{
    public static bool $failDecryption = false;
    public static bool $keyUnavailable = false;

    public function encrypt(string $value): string
    {
        return 'glpi-encrypted:' . base64_encode($value);
    }

    public function decrypt(?string $value): ?string
    {
        if (self::$failDecryption) {
            trigger_error('Synthetic cryptographic diagnostic.', E_USER_WARNING);
            return '';
        }
        if (self::$keyUnavailable) {
            return $value;
        }
        if (!is_string($value) || !str_starts_with($value, 'glpi-encrypted:')) {
            return '';
        }
        $decrypted = base64_decode(substr($value, strlen('glpi-encrypted:')), true);
        return is_string($decrypted) ? $decrypted : '';
    }
}

/** Simulates GLPI Config: writes secured values encrypted and reads raw storage. */
class Config
{
    /** @var array<string, array<string, string>> */
    public static array $storage = [];

    public static function getConfigurationValues(string $context, array $names = []): array
    {
        $values = self::$storage[$context] ?? [];
        if ($names !== []) {
            return array_intersect_key($values, array_fill_keys($names, true));
        }
        return $values;
    }

    public static function setConfigurationValues(string $context, array $values = []): void
    {
        $key = new GLPIKey();
        foreach ($values as $name => $value) {
            $value = (string) $value;
            if ($name === 'api_token' && $value !== '') {
                $value = $key->encrypt($value);
            }
            self::$storage[$context][$name] = $value;
        }
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

$knowledgeTemplate = file_get_contents(__DIR__ . '/../templates/knowledge.html.twig');
$assert(is_string($knowledgeTemplate), 'Knowledge Twig template is readable.');
$assert(str_contains($knowledgeTemplate, 'layout/page_without_tabs.html.twig'), 'Knowledge page uses the GLPI 11 page layout.');
$assert(str_contains($knowledgeTemplate, 'breadcrumbs') && str_contains($knowledgeTemplate, 'No items are available here.'), 'Knowledge UI includes breadcrumbs and an empty state.');
$assert(!str_contains(strtolower($knowledgeTemplate), '<iframe') && !str_contains($knowledgeTemplate, 'page.html'), 'Knowledge UI does not embed BookStack or render page HTML.');
$knowledgeControllerSource = file_get_contents(__DIR__ . '/../src/Controller/KnowledgeController.php');
$assert(
    str_contains($knowledgeTemplate, "path('/plugins/bookstackbridge/knowledge')")
        && str_contains($knowledgeTemplate, 'path(crumb.href)')
        && str_contains($knowledgeTemplate, 'path(item.href)')
        && !str_contains($knowledgeTemplate, 'path(\'bookstackbridge_'),
    'Knowledge links use GLPI prefixed resource paths instead of unresolved plugin route names.'
);
$assert(
    str_contains((string) $knowledgeControllerSource, "'/plugins/bookstackbridge/knowledge/books/'")
        && str_contains((string) $knowledgeControllerSource, "'/chapters/'")
        && str_contains((string) $knowledgeControllerSource, "'/pages/'"),
    'Generated links preserve book, chapter, and page context in plugin route resources.'
);
$assert(is_string($knowledgeControllerSource) && substr_count($knowledgeControllerSource, "Session::haveRight('knowbase', READ)") === 1, 'All knowledge routes share the native GLPI knowledge-base read permission.');
$assert(str_contains((string) $knowledgeControllerSource, "methods: ['GET']"), 'Structural navigation routes are read-only GET routes.');

$assert(
    str_contains($template, 'name="api_token_id"')
        && str_contains($template, 'name="api_token"')
        && !str_contains($template, 'value="{{ config.api_token_id')
        && !str_contains($template, 'value="{{ config.api_token|'),
    'Credential inputs do not render stored values.'
);

$navigationRequests = [];
$navigationClient = new BookStackClient(
    'https://docs.example.org',
    'synthetic-id',
    'synthetic-secret',
    static function (string $url, array $headers) use (&$navigationRequests): array {
        $navigationRequests[] = $url;
        if (str_contains($url, '/api/shelves?')) {
            return ['status' => 200, 'body' => json_encode(['data' => [['id' => 7, 'name' => 'Operations', 'description' => 'Team guidance', 'url' => 'https://internal.example.test/shelves/7']], 'total' => 101])];
        }
        if (str_ends_with($url, '/api/shelves/7')) {
            return ['status' => 200, 'body' => json_encode(['id' => 7, 'name' => 'Operations', 'books' => [['id' => 21, 'name' => 'Handbook']]])];
        }
        if (str_ends_with($url, '/api/books/21')) {
            return ['status' => 200, 'body' => json_encode([
                'id' => 21, 'name' => 'Handbook',
                'shelves' => [['id' => 7, 'name' => 'Operations'], ['id' => 8, 'name' => 'Reference']],
                'contents' => [
                    ['id' => 31, 'name' => 'Getting started', 'type' => 'chapter', 'pages' => [['id' => 41, 'name' => 'First steps', 'html' => '<script>not rendered</script>']]],
                    ['id' => 42, 'name' => 'Book overview', 'type' => 'page', 'html' => '<script>not rendered</script>'],
                ],
            ])];
        }
        return ['status' => 404, 'body' => '{}'];
    },
    '',
    static fn (string $host): array => ['1.1.1.1']
);
$navigation = new NavigationService($navigationClient);
$shelfPage = $navigation->shelves(2);
$assert($shelfPage['total'] === 101 && $shelfPage['page'] === 2 && $shelfPage['pages'] === 2, 'Shelf navigation exposes bounded page metadata.');
$assert(str_contains($navigationRequests[0], 'count=100&offset=100'), 'Shelf pagination uses the BookStack count and offset parameters.');
$assert(!isset($shelfPage['items'][0]['url']), 'Navigation normalization discards API URLs that may expose internal hosts.');
$shelfDetail = $navigation->shelf(7);
$assert($shelfDetail['books'][0]['id'] === 21, 'Shelf detail exposes its books from the official shelf relation.');
$bookStructure = $navigation->book(21);
$assert(count($bookStructure['shelves']) === 2, 'Book structure retains all shelf memberships for multi-shelf books.');
$assert($bookStructure['contents'][0]['type'] === 'chapter' && $bookStructure['contents'][0]['pages'][0]['name'] === 'First steps', 'Book structure includes chapters and their page titles.');
$assert($bookStructure['contents'][1]['type'] === 'page', 'Book structure supports pages directly under a book.');
$assert(!isset($bookStructure['contents'][0]['pages'][0]['html']), 'Navigation strips page HTML and other unapproved BookStack fields.');
$chapterDetail = $navigation->chapter(21, 31);
$pageDetail = $navigation->page(21, 41);
$directPageDetail = $navigation->page(21, 42);
$assert($chapterDetail['pages'][0]['id'] === 41 && $pageDetail['chapter']['id'] === 31, 'Chapter and nested page relationships resolve within the selected book.');
$assert($directPageDetail['chapter'] === null, 'Pages directly under a book resolve without a chapter.');
$navigationRequestCount = count($navigationRequests);
try {
    $navigation->book(0);
    $assert(false, 'Invalid BookStack IDs must be rejected.');
} catch (BookStackException) {
    $assert(count($navigationRequests) === $navigationRequestCount, 'Invalid IDs fail before making an API request.');
}
try {
    $navigation->chapter(21, 999);
    $assert(false, 'A chapter absent from the selected book must fail safely.');
} catch (BookStackException $e) {
    $assert($e->statusCode === 404 && !str_contains($e->getMessage(), '999'), 'Missing hierarchy items produce a safe unavailable response.');
}
$malformedNavigation = new NavigationService(new BookStackClient(
    'https://docs.example.org', 'id', 'secret',
    static fn (): array => ['status' => 200, 'body' => '{"id":21,"name":"Handbook","contents":"unexpected"}'],
    '', static fn (string $host): array => ['1.1.1.1']
));
try {
    $malformedNavigation->book(21);
    $assert(false, 'Unexpected BookStack hierarchy shapes must fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'structure'), 'Unexpected hierarchy responses return a safe error.');
}
$controllerSource = file_get_contents(__DIR__ . '/../src/Controller/ConfigController.php');
$assert(
    is_string($controllerSource)
        && str_contains($controllerSource, '$config[\'api_token_id\'] = \'\';')
        && str_contains($controllerSource, '$config[\'api_token\'] = \'\';')
        && str_contains($controllerSource, 'ConfigRepository::getConfigurationValuesForSubmission($submittedTokenSecret)'),
    'Controller clears credentials before rendering and normalizes credentials before merge.'
);

Config::setConfigurationValues('plugin:bookstackbridge', [
    'base_url' => 'https://docs.example.org',
    'allowed_private_ips' => '',
    'api_token_id' => 'synthetic-id',
    'api_token' => 'synthetic-secret',
]);
$rawStoredSecret = Config::getConfigurationValues('plugin:bookstackbridge')['api_token'];
$assert($rawStoredSecret !== 'synthetic-secret', 'GLPI secured config stores the token secret encrypted.');
$normalizedConfig = ConfigRepository::getConfigurationValues();
$assert(
    $normalizedConfig['api_token_id'] === 'synthetic-id'
        && $normalizedConfig['api_token'] === 'synthetic-secret',
    'Configuration repository decrypts the stored secret once and leaves Token ID unchanged.'
);

$partialUpdate = ConfigSubmission::merge($normalizedConfig, 'https://docs.example.org', [], '', '');
ConfigRepository::setConfigurationValues($partialUpdate);
$assert(
    Config::getConfigurationValues('plugin:bookstackbridge')['api_token'] === $rawStoredSecret
        && ConfigRepository::getConfigurationValues()['api_token'] === 'synthetic-secret',
    'Partial save preserves the existing secret without adding an encryption layer.'
);

$idOnlyUpdate = ConfigSubmission::merge(ConfigRepository::getConfigurationValues(), 'https://docs.example.org', [], 'replacement-id', '');
ConfigRepository::setConfigurationValues($idOnlyUpdate);
$afterIdUpdate = ConfigRepository::getConfigurationValues();
$assert(
    $afterIdUpdate['api_token_id'] === 'replacement-id'
        && $afterIdUpdate['api_token'] === 'synthetic-secret',
    'Updating only Token ID preserves the decrypted secret.'
);

$secretOnlyUpdate = ConfigSubmission::merge($afterIdUpdate, 'https://docs.example.org', [], '', 'replacement-secret');
ConfigRepository::setConfigurationValues($secretOnlyUpdate);
$afterSecretUpdate = ConfigRepository::getConfigurationValues();
$assert(
    $afterSecretUpdate['api_token_id'] === 'replacement-id'
        && $afterSecretUpdate['api_token'] === 'replacement-secret',
    'Updating only Token Secret preserves Token ID and stores a decryptable secret.'
);

for ($save = 0; $save < 3; $save++) {
    $repeatedSave = ConfigSubmission::merge(ConfigRepository::getConfigurationValues(), 'https://docs.example.org', [], '', '');
    ConfigRepository::setConfigurationValues($repeatedSave);
}
$assert(
    ConfigRepository::getConfigurationValues()['api_token'] === 'replacement-secret',
    'Repeated partial saves keep the same single encrypted storage layer.'
);

$authHeaders = [];
$repositoryClient = new BookStackClient(
    'https://docs.example.org',
    (string) $afterSecretUpdate['api_token_id'],
    (string) $afterSecretUpdate['api_token'],
    static function (string $url, array $headers) use (&$authHeaders): array {
        $authHeaders = $headers;
        return ['status' => 200, 'body' => '{"data":[],"total":0}'];
    },
    '',
    static fn (string $host): array => ['1.1.1.1']
);
$repositoryClient->testConnection();
$assert(
    in_array('Authorization: Token replacement-id:replacement-secret', $authHeaders, true),
    'BookStack client receives plaintext credentials and assembles the expected Authorization header.'
);

GLPIKey::$failDecryption = true;
try {
    ConfigRepository::getConfigurationValues();
    $assert(false, 'Credential decryption failure must be reported.');
} catch (ConfigurationException $e) {
    $assert(
        str_contains($e->getMessage(), 'Enter the token secret again')
            && !str_contains($e->getMessage(), 'synthetic-secret')
            && !str_contains($e->getMessage(), $rawStoredSecret),
        'Decryption failure returns a safe recovery message without credential or ciphertext.'
    );
}
GLPIKey::$failDecryption = false;

GLPIKey::$keyUnavailable = true;
try {
    ConfigRepository::getConfigurationValues();
    $assert(false, 'Unavailable GLPI key must not pass ciphertext to the API client.');
} catch (ConfigurationException $e) {
    $replacement = ConfigRepository::getConfigurationValuesForSubmission('recovery-secret');
    $assert(
        $replacement['api_token'] === ''
            && $replacement['api_token_id'] === 'replacement-id',
        'Unreadable stored secret can be replaced without carrying ciphertext into the merge.'
    );
    $recovered = ConfigSubmission::merge($replacement, 'https://docs.example.org', [], '', 'recovery-secret');
    ConfigRepository::setConfigurationValues($recovered);
}
GLPIKey::$keyUnavailable = false;
$assert(
    ConfigRepository::getConfigurationValues()['api_token'] === 'recovery-secret',
    'Saving a replacement secret recovers configuration when the prior ciphertext is unreadable.'
);

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
    $renderedKnowledge = $twig->render('@bookstackbridge/knowledge.html.twig', [
        'view' => 'book', 'title' => 'Handbook', 'book' => ['id' => 21, 'name' => 'Handbook'],
        'items' => [['id' => 31, 'name' => 'Getting started', 'type' => 'chapter', 'href' => '/plugins/bookstackbridge/knowledge/books/21/chapters/31']],
        'shelves' => [['id' => 7, 'name' => 'Operations', 'href' => '/plugins/bookstackbridge/knowledge/shelves/7']],
        'breadcrumbs' => [['label' => 'Shelves', 'href' => '/plugins/bookstackbridge/knowledge']],
        'error' => null, 'notice' => null, 'canConfigure' => true,
    ]);
    $assert(str_contains($renderedKnowledge, 'href="/glpi/plugins/bookstackbridge/knowledge/books/21/chapters/31"'), 'Knowledge navigation renders root_doc-prefixed links through GLPI Twig when GLPI_ROOT is available.');
    $assert(str_contains($renderedKnowledge, 'href="/glpi/plugins/bookstackbridge/"'), 'The knowledge template renders a root_doc-prefixed settings link for administrators.');
    $assert(str_contains($renderedKnowledge, 'Handbook') && !str_contains($renderedKnowledge, '<iframe'), 'Rendered knowledge template contains structure without embedding BookStack.');
}

$menu = Menu::getMenuContent();
$assert($menu['title'] === 'BookStack knowledge base', 'Menu builds successfully with GLPI global classes.');
$assert($menu['page'] === '/plugins/bookstackbridge/knowledge', 'Menu points to structural knowledge navigation.');
$assert(Session::haveRight('knowbase', READ), 'Structural navigation requires GLPI knowledge-base read access, not config update.');
Session::$canConfigure = false;
Session::$canReadKnowledge = false;
$assert(Menu::getMenuContent() === [], 'Menu stays hidden without GLPI knowledge-base read permission.');
Session::$canReadKnowledge = true;
$assert(Menu::getMenuContent()['page'] === '/plugins/bookstackbridge/knowledge', 'Knowledge navigation remains available to readers without configuration update permission.');
Session::$canConfigure = true;
$assert(Menu::getMenuContent()['page'] === '/plugins/bookstackbridge/knowledge', 'Users with both rights enter the read-only knowledge interface.');
Session::$canReadKnowledge = false;
$assert(Menu::getMenuContent()['page'] === '/plugins/bookstackbridge/', 'Configuration administrators without knowledge read access retain a settings entry point.');
Session::$canReadKnowledge = true;

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
