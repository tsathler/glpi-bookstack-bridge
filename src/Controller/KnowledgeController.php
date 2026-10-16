<?php

namespace GlpiPlugin\Bookstackbridge\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;
use GlpiPlugin\Bookstackbridge\ConfigRepository;
use GlpiPlugin\Bookstackbridge\ConfigurationException;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;
use GlpiPlugin\Bookstackbridge\Service\NavigationService;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class KnowledgeController extends AbstractController
{
    #[Route('/knowledge', name: 'bookstackbridge_knowledge', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->show($request, 'shelves');
    }

    #[Route('/knowledge/shelves/{shelfId}', name: 'bookstackbridge_shelf', methods: ['GET'], requirements: ['shelfId' => '\\d+'])]
    public function shelf(string $shelfId): Response
    {
        return $this->renderNavigation(function (NavigationService $navigation) use ($shelfId): array {
            $shelf = $navigation->shelf($this->parseId($shelfId));
            $shelf['item']['href'] = '/plugins/bookstackbridge/knowledge/shelves/' . $shelf['item']['id'];
            foreach ($shelf['books'] as &$book) {
                $book['href'] = '/plugins/bookstackbridge/knowledge/books/' . $book['id'];
            }
            unset($book);
            return ['view' => 'shelf', 'title' => $shelf['item']['name'], 'shelf' => $shelf['item'], 'items' => $shelf['books'], 'breadcrumbs' => []];
        });
    }

    #[Route('/knowledge/books/{bookId}', name: 'bookstackbridge_book', methods: ['GET'], requirements: ['bookId' => '\\d+'])]
    public function book(string $bookId): Response
    {
        return $this->renderNavigation(function (NavigationService $navigation) use ($bookId): array {
            $result = $navigation->book($this->parseId($bookId));
            $id = $result['book']['id'];
            foreach ($result['contents'] as &$entry) {
                $entry['href'] = $entry['type'] === 'chapter'
                    ? '/plugins/bookstackbridge/knowledge/books/' . $id . '/chapters/' . $entry['id']
                    : '/plugins/bookstackbridge/knowledge/books/' . $id . '/pages/' . $entry['id'];
            }
            unset($entry);
            foreach ($result['shelves'] as &$shelf) {
                $shelf['href'] = '/plugins/bookstackbridge/knowledge/shelves/' . $shelf['id'];
            }
            unset($shelf);
            return ['view' => 'book', 'title' => $result['book']['name'], 'book' => $result['book'], 'items' => $result['contents'], 'shelves' => $result['shelves'], 'breadcrumbs' => []];
        });
    }

    #[Route('/knowledge/books/{bookId}/chapters/{chapterId}', name: 'bookstackbridge_chapter', methods: ['GET'], requirements: ['bookId' => '\\d+', 'chapterId' => '\\d+'])]
    public function chapter(string $bookId, string $chapterId): Response
    {
        return $this->renderNavigation(function (NavigationService $navigation) use ($bookId, $chapterId): array {
            $result = $navigation->chapter($this->parseId($bookId), $this->parseId($chapterId));
            $bookHref = '/plugins/bookstackbridge/knowledge/books/' . $result['book']['id'];
            foreach ($result['pages'] as &$page) {
                $page['href'] = $bookHref . '/pages/' . $page['id'];
            }
            unset($page);
            return ['view' => 'chapter', 'title' => $result['chapter']['name'], 'book' => $result['book'], 'chapter' => $result['chapter'], 'items' => $result['pages'], 'breadcrumbs' => [['label' => $result['book']['name'], 'href' => $bookHref]]];
        });
    }

    #[Route('/knowledge/books/{bookId}/pages/{pageId}', name: 'bookstackbridge_page', methods: ['GET'], requirements: ['bookId' => '\\d+', 'pageId' => '\\d+'])]
    public function page(string $bookId, string $pageId): Response
    {
        return $this->renderNavigation(function (NavigationService $navigation) use ($bookId, $pageId): array {
            $result = $navigation->page($this->parseId($bookId), $this->parseId($pageId));
            $bookHref = '/plugins/bookstackbridge/knowledge/books/' . $result['book']['id'];
            $breadcrumbs = [['label' => $result['book']['name'], 'href' => $bookHref]];
            if ($result['chapter'] !== null) {
                $breadcrumbs[] = ['label' => $result['chapter']['name'], 'href' => $bookHref . '/chapters/' . $result['chapter']['id']];
            }
            return ['view' => 'page', 'title' => $result['page']['name'], 'book' => $result['book'], 'page' => $result['page'], 'breadcrumbs' => $breadcrumbs];
        });
    }

    private function show(Request $request, string $view): Response
    {
        $rawPage = $request->query->get('page', '1');
        $page = is_scalar($rawPage) && ctype_digit((string) $rawPage) ? (int) $rawPage : 0;
        return $this->renderNavigation(function (NavigationService $navigation) use ($page, $view): array {
            $result = $navigation->shelves($page);
            foreach ($result['items'] as &$shelf) {
                $shelf['href'] = '/plugins/bookstackbridge/knowledge/shelves/' . $shelf['id'];
            }
            unset($shelf);
            return ['view' => $view, 'title' => 'BookStack knowledge', 'items' => $result['items'], 'pagination' => $result, 'breadcrumbs' => []];
        });
    }

    /** @param callable(NavigationService): array<string, mixed> $build */
    private function renderNavigation(callable $build): Response
    {
        if (!Session::haveRight('knowbase', READ)) {
            throw $this->createAccessDeniedException();
        }
        try {
            $config = ConfigRepository::getConfigurationValues();
            $client = new BookStackClient(
                (string) ($config['base_url'] ?? ''),
                (string) ($config['api_token_id'] ?? ''),
                (string) ($config['api_token'] ?? ''),
                null,
                BaseUrlPolicy::parsePrivateIpAllowlist((string) ($config['allowed_private_ips'] ?? ''))
            );
            $data = $build(new NavigationService($client));
            return $this->render('@bookstackbridge/knowledge.html.twig', $data + [
                'error' => null,
                'notice' => null,
                'canConfigure' => Session::haveRight('config', UPDATE),
            ]);
        } catch (ConfigurationException|BookStackException $e) {
            return $this->render('@bookstackbridge/knowledge.html.twig', [
                'view' => 'error', 'title' => 'BookStack knowledge', 'items' => [], 'breadcrumbs' => [],
                'error' => $e->getMessage(), 'notice' => null,
                'canConfigure' => Session::haveRight('config', UPDATE),
            ]);
        }
    }

    private function parseId(string $value): int
    {
        if (strlen($value) > 18 || !ctype_digit($value) || (int) $value < 1) {
            throw new BookStackException('The requested BookStack item is invalid.');
        }
        return (int) $value;
    }
}
