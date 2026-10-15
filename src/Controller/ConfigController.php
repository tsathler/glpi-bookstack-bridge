<?php

namespace GlpiPlugin\Bookstackbridge\Controller;

use Config;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    private const CONTEXT = 'plugin:bookstackbridge';

    #[Route('/', name: 'bookstackbridge_home', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireAdmin();
        return $this->render('@bookstackbridge/config.html.twig', [
            'config' => Config::getConfigurationValues(self::CONTEXT),
            'result' => null,
            'error' => null,
            'query' => '',
        ]);
    }

    #[Route('/configure', name: 'bookstackbridge_configure', methods: ['POST'])]
    public function configure(Request $request): Response
    {
        $this->requireAdmin();
        $current = Config::getConfigurationValues(self::CONTEXT);
        try {
            $url = BaseUrlPolicy::validate((string) $request->request->get('base_url', ''));
        } catch (BookStackException $e) {
            return $this->renderPage($current, null, $e->getMessage(), '');
        }
        $tokenId = trim((string) $request->request->get('api_token_id', ''));
        $token = trim((string) $request->request->get('api_token', ''));
        if ($token === '') {
            $token = (string) ($current['api_token'] ?? '');
        }
        if ($tokenId === '' || $token === '') {
            return $this->renderPage($current, null, 'Token ID and secret are required.', '');
        }
        Config::setConfigurationValues(self::CONTEXT, [
            'base_url' => $url,
            'api_token_id' => $tokenId,
            'api_token' => $token,
        ]);
        return $this->renderPage(Config::getConfigurationValues(self::CONTEXT), null, null, 'Configuration saved.');
    }

    #[Route('/test', name: 'bookstackbridge_test', methods: ['POST'])]
    public function test(): Response
    {
        $this->requireAdmin();
        try {
            $result = $this->client()->testConnection();
            return $this->renderPage(Config::getConfigurationValues(self::CONTEXT), $result, null, 'Connection successful.');
        } catch (BookStackException $e) {
            return $this->renderPage(Config::getConfigurationValues(self::CONTEXT), null, $e->getMessage(), '');
        }
    }

    #[Route('/search', name: 'bookstackbridge_search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        $this->requireAdmin();
        $query = trim((string) $request->request->get('query', ''));
        try {
            $result = $this->client()->search($query);
            $result['data'] = $this->safeResults($result['data']);
            return $this->renderPage(Config::getConfigurationValues(self::CONTEXT), $result, null, '', $query);
        } catch (BookStackException $e) {
            return $this->renderPage(Config::getConfigurationValues(self::CONTEXT), null, $e->getMessage(), '', $query);
        }
    }

    private function client(): BookStackClient
    {
        $config = Config::getConfigurationValues(self::CONTEXT);
        return new BookStackClient(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['api_token_id'] ?? ''),
            (string) ($config['api_token'] ?? '')
        );
    }

    private function renderPage(array $config, ?array $result, ?string $error, string $notice, string $query = ''): Response
    {
        return $this->render('@bookstackbridge/config.html.twig', compact('config', 'result', 'error', 'notice', 'query'));
    }

    private function safeResults(array $items): array
    {
        $config = Config::getConfigurationValues(self::CONTEXT);
        $configuredHost = strtolower((string) parse_url((string) ($config['base_url'] ?? ''), PHP_URL_HOST));
        foreach ($items as &$item) {
            $url = (string) ($item['url'] ?? '');
            $parts = parse_url($url);
            $item['safe_url'] = is_array($parts)
                && strtolower($parts['scheme'] ?? '') === 'https'
                && strtolower($parts['host'] ?? '') === $configuredHost
                && !isset($parts['user']) && !isset($parts['pass'])
                ? $url
                : '';
        }
        unset($item);
        return $items;
    }

    private function requireAdmin(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            throw $this->createAccessDeniedException();
        }
    }
}
