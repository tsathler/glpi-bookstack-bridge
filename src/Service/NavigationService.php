<?php

namespace GlpiPlugin\Bookstackbridge\Service;

use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;

/** Presents only structural metadata from the BookStack API. */
final class NavigationService
{
    public function __construct(private readonly BookStackClient $client)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public function shelves(int $page = 1): array
    {
        if ($page < 1 || $page > 10000) {
            throw new BookStackException('The requested shelf page is invalid.');
        }
        $result = $this->client->listShelves($page);
        return [
            'items' => array_map(fn (array $item): array => $this->node($item), $result['data']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / 100)),
        ];
    }

    /** @return array<string, mixed> */
    public function shelf(int $id): array
    {
        $shelf = $this->client->readShelf($this->validId($id));
        $this->assertResource($shelf, 'shelf');
        $books = $shelf['books'] ?? [];
        if (!is_array($books) || !array_is_list($books)) {
            throw new BookStackException('BookStack returned an unexpected shelf structure.');
        }
        return ['item' => $this->node($shelf), 'books' => array_map(fn (array $book): array => $this->node($book), $this->arrayItems($books))];
    }

    /** @return array{book: array<string, mixed>, contents: list<array<string, mixed>>, shelves: list<array<string, mixed>>} */
    public function book(int $id): array
    {
        $book = $this->client->readBook($this->validId($id));
        $this->assertResource($book, 'book');
        $contents = $book['contents'] ?? [];
        $shelves = $book['shelves'] ?? [];
        if (!is_array($contents) || !array_is_list($contents) || !is_array($shelves) || !array_is_list($shelves)) {
            throw new BookStackException('BookStack returned an unexpected book structure.');
        }
        $normalized = [];
        foreach ($this->arrayItems($contents) as $entry) {
            $type = $entry['type'] ?? '';
            if ($type === 'chapter') {
                $this->assertResource($entry, 'chapter');
                $pages = $entry['pages'] ?? [];
                if (!is_array($pages) || !array_is_list($pages)) {
                    throw new BookStackException('BookStack returned an unexpected chapter structure.');
                }
                $normalized[] = $this->node($entry) + ['type' => 'chapter', 'pages' => array_map(fn (array $item): array => $this->node($item) + ['type' => 'page'], $this->arrayItems($pages))];
            } elseif ($type === 'page') {
                $normalized[] = $this->node($entry) + ['type' => 'page'];
            } else {
                throw new BookStackException('BookStack returned an unsupported book content structure.');
            }
        }
        return [
            'book' => $this->node($book),
            'contents' => $normalized,
            'shelves' => array_map(fn (array $item): array => $this->node($item), $this->arrayItems($shelves)),
        ];
    }

    /** @return array{book: array<string, mixed>, chapter: array<string, mixed>, pages: list<array<string, mixed>>} */
    public function chapter(int $bookId, int $chapterId): array
    {
        $result = $this->book($bookId);
        foreach ($result['contents'] as $entry) {
            if (($entry['type'] ?? '') === 'chapter' && $entry['id'] === $this->validId($chapterId)) {
                return ['book' => $result['book'], 'chapter' => $entry, 'pages' => $entry['pages']];
            }
        }
        throw new BookStackException('The requested chapter is unavailable in this book.', 404);
    }

    /** @return array{book: array<string, mixed>, chapter: ?array, page: array<string, mixed>} */
    public function page(int $bookId, int $pageId): array
    {
        $result = $this->book($bookId);
        foreach ($result['contents'] as $entry) {
            if (($entry['type'] ?? '') === 'page' && $entry['id'] === $this->validId($pageId)) {
                return ['book' => $result['book'], 'chapter' => null, 'page' => $entry];
            }
            if (($entry['type'] ?? '') === 'chapter') {
                foreach ($entry['pages'] as $page) {
                    if ($page['id'] === $this->validId($pageId)) {
                        return ['book' => $result['book'], 'chapter' => $entry, 'page' => $page];
                    }
                }
            }
        }
        throw new BookStackException('The requested page is unavailable in this book.', 404);
    }

    private function validId(int $id): int
    {
        if ($id < 1) {
            throw new BookStackException('The requested BookStack item is invalid.');
        }
        return $id;
    }

    private function assertResource(array $item, string $type): void
    {
        $id = $item['id'] ?? null;
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1 || !is_string($item['name'] ?? null)) {
            throw new BookStackException('BookStack returned an unexpected ' . $type . ' structure.');
        }
    }

    /** @return array<string, mixed> */
    private function node(array $item): array
    {
        $this->assertResource($item, 'navigation item');
        $result = ['id' => (int) $item['id'], 'name' => $item['name']];
        if (isset($item['description']) && is_string($item['description'])) {
            $result['description'] = $item['description'];
        }
        return $result;
    }

    /** @param list<mixed> $items @return list<array<string, mixed>> */
    private function arrayItems(array $items): array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new BookStackException('BookStack returned an unexpected navigation structure.');
            }
        }
        return $items;
    }
}
