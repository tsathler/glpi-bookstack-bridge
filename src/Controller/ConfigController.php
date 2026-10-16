<?php

namespace GlpiPlugin\Bookstackbridge\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;
use GlpiPlugin\Bookstackbridge\ConfigRepository;
use GlpiPlugin\Bookstackbridge\ConfigSubmission;
use GlpiPlugin\Bookstackbridge\ConfigurationException;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/', name: 'bookstackbridge_home', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireAdmin();
        try {
            return $this->renderPage(ConfigRepository::getConfigurationValues(), null, null, '');
        } catch (ConfigurationException $e) {
            return $this->renderPage(ConfigRepository::getNonSecretConfigurationValues(), null, $e->getMessage(), '');
        }
    }

    #[Route('/configure', name: 'bookstackbridge_configure', methods: ['GET', 'POST'])]
    public function configure(Request $request): Response
    {
        $this->requireAdmin();
        $this->requirePost($request);
        $submittedUrl = (string) $request->request->get('base_url', '');
        $submittedAllowlist = (string) $request->request->get('allowed_private_ips', '');
        $submittedTokenSecret = (string) $request->request->get('api_token', '');
        try {
            $current = ConfigRepository::getConfigurationValuesForSubmission($submittedTokenSecret);
        } catch (ConfigurationException $e) {
            $current = ConfigRepository::getNonSecretConfigurationValues();
            $current['base_url'] = $submittedUrl;
            $current['allowed_private_ips'] = $submittedAllowlist;
            return $this->renderPage($current, null, $e->getMessage(), '');
        }
        try {
            $privateIpAllowlist = BaseUrlPolicy::parsePrivateIpAllowlist(
                $submittedAllowlist
            );
            $url = BaseUrlPolicy::validate($submittedUrl, $privateIpAllowlist);
        } catch (BookStackException $e) {
            $current['base_url'] = $submittedUrl;
            $current['allowed_private_ips'] = $submittedAllowlist;
            return $this->renderPage($current, null, $e->getMessage(), '');
        }
        $updated = ConfigSubmission::merge(
            $current,
            $url,
            $privateIpAllowlist,
            (string) $request->request->get('api_token_id', ''),
            $submittedTokenSecret
        );
        ConfigRepository::setConfigurationValues($updated);
        try {
            return $this->renderPage(ConfigRepository::getConfigurationValues(), null, null, 'Configuration saved.');
        } catch (ConfigurationException $e) {
            return $this->renderPage(ConfigRepository::getNonSecretConfigurationValues(), null, $e->getMessage(), '');
        }
    }

    #[Route('/test', name: 'bookstackbridge_test', methods: ['GET', 'POST'])]
    public function test(Request $request): Response
    {
        $this->requireAdmin();
        $this->requirePost($request);
        try {
            $config = ConfigRepository::getConfigurationValues();
            $result = $this->client($config)->testConnection();
            return $this->renderPage($config, $result, null, 'Connection successful.');
        } catch (ConfigurationException $e) {
            return $this->renderPage(ConfigRepository::getNonSecretConfigurationValues(), null, $e->getMessage(), '');
        } catch (BookStackException $e) {
            return $this->renderPage($this->configurationForDisplay(), null, $e->getMessage(), '');
        }
    }

    #[Route('/search', name: 'bookstackbridge_search', methods: ['GET', 'POST'])]
    public function search(Request $request): Response
    {
        $this->requireAdmin();
        $this->requirePost($request);
        $query = trim((string) $request->request->get('query', ''));
        try {
            $config = ConfigRepository::getConfigurationValues();
            $result = $this->client($config)->search($query);
            $result['data'] = $this->safeResults($result['data'], (string) ($config['base_url'] ?? ''));
            return $this->renderPage($config, $result, null, '', $query);
        } catch (ConfigurationException $e) {
            return $this->renderPage(ConfigRepository::getNonSecretConfigurationValues(), null, $e->getMessage(), '', $query);
        } catch (BookStackException $e) {
            return $this->renderPage($this->configurationForDisplay(), null, $e->getMessage(), '', $query);
        }
    }

    /** @param array<string, mixed> $config */
    private function client(array $config): BookStackClient
    {
        return new BookStackClient(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['api_token_id'] ?? ''),
            (string) ($config['api_token'] ?? ''),
            null,
            BaseUrlPolicy::parsePrivateIpAllowlist((string) ($config['allowed_private_ips'] ?? ''))
        );
    }

    private function renderPage(array $config, ?array $result, ?string $error, string $notice, string $query = ''): Response
    {
        $networkConfigured = trim((string) ($config['base_url'] ?? '')) !== '';
        $credentialsComplete = trim((string) ($config['api_token_id'] ?? '')) !== ''
            && trim((string) ($config['api_token'] ?? '')) !== '';
        $integrationReady = $networkConfigured && $credentialsComplete;
        $canBrowseKnowledge = Session::haveRight('knowbase', READ);
        $config['api_token_id'] = '';
        $config['api_token'] = '';
        return $this->render('@bookstackbridge/config.html.twig', compact(
            'config',
            'result',
            'error',
            'notice',
            'query',
            'networkConfigured',
            'credentialsComplete',
            'integrationReady',
            'canBrowseKnowledge'
        ));
    }

    private function safeResults(array $items, string $baseUrl): array
    {
        $configuredHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
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

    /** @return array<string, mixed> */
    private function configurationForDisplay(): array
    {
        try {
            return ConfigRepository::getConfigurationValues();
        } catch (ConfigurationException) {
            return ConfigRepository::getNonSecretConfigurationValues();
        }
    }

    private function requireAdmin(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function requirePost(Request $request): void
    {
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST']);
        }
    }
}
