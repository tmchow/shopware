<?php declare(strict_types=1);

namespace Shopware\Core\Content\Seo;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @phpstan-import-type ResolvedSeoUrl from AbstractSeoResolver
 */
#[Package('inventory')]
class SeoResolver extends AbstractSeoResolver
{
    /**
     * @internal
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getDecorated(): AbstractSeoResolver
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @return ResolvedSeoUrl
     */
    public function resolve(string $languageId, string $salesChannelId, string $pathInfo): array
    {
        return $this->resolveWithQueryString($languageId, $salesChannelId, $pathInfo, null);
    }

    /**
     * @return ResolvedSeoUrl
     */
    public function resolveWithQueryString(string $languageId, string $salesChannelId, string $pathInfo, ?string $queryString): array
    {
        $seoPathInfo = trim($pathInfo, '/');

        $query = (new QueryBuilder($this->connection))
            ->select('id', 'path_info pathInfo', 'seo_path_info seoPathInfo', 'is_canonical isCanonical', 'sales_channel_id salesChannelId')
            ->from('seo_url')
            ->where('language_id = :language_id')
            ->andWhere('(sales_channel_id = :sales_channel_id OR sales_channel_id IS NULL)');

        $seoPathConditions = [
            'seo_path_info = :seoPath',
            'seo_path_info = :seoPathWithSlash',
            'IF(LOCATE(\'?\', seo_path_info) > 0, LEFT(seo_path_info, LOCATE(\'?\', seo_path_info) - 1), seo_path_info) = :seoPath',
            'IF(LOCATE(\'?\', seo_path_info) > 0, LEFT(seo_path_info, LOCATE(\'?\', seo_path_info) - 1), seo_path_info) = :seoPathWithSlash',
        ];

        $query->setParameter('language_id', Uuid::fromHexToBytes($languageId))
            ->setParameter('sales_channel_id', Uuid::fromHexToBytes($salesChannelId))
            ->setParameter('seoPath', $seoPathInfo)
            ->setParameter('seoPathWithSlash', $seoPathInfo . '/');

        if ($queryString !== null) {
            $seoPathConditions[] = 'seo_path_info = :seoPathWithQuery';
            $seoPathConditions[] = 'seo_path_info = :seoPathWithSlashAndQuery';
            $query->setParameter('seoPathWithQuery', $seoPathInfo . '?' . $queryString)
                ->setParameter('seoPathWithSlashAndQuery', $seoPathInfo . '/?' . $queryString);
        }

        $query->andWhere('(' . implode(' OR ', $seoPathConditions) . ')');

        $query->setTitle('seo-url::resolve');

        $seoPaths = $query->executeQuery()->fetchAllAssociative();
        $requestQueryParams = null;
        if ($queryString !== null) {
            $requestQueryParams = self::parseQueryParameters($queryString);

            $seoPaths = array_values(array_filter($seoPaths, static function (array $seoPath) use ($seoPathInfo, $queryString, $requestQueryParams): bool {
                [$priority] = self::calculateQueryMatch($seoPathInfo, $queryString, $requestQueryParams, (string) ($seoPath['seoPathInfo'] ?? ''));

                return $priority > 0;
            }));
        }

        // Prefer exact query-string matches first, then controlled query fallback matches,
        // then plain path matches. Afterwards sort by canonical and sales-channel specificity.
        usort($seoPaths, static function ($a, $b) use ($seoPathInfo, $queryString, $requestQueryParams) {
            if ($queryString !== null && $requestQueryParams !== null) {
                [$aPriority, $aSpecificity] = self::calculateQueryMatch($seoPathInfo, $queryString, $requestQueryParams, (string) ($a['seoPathInfo'] ?? ''));
                [$bPriority, $bSpecificity] = self::calculateQueryMatch($seoPathInfo, $queryString, $requestQueryParams, (string) ($b['seoPathInfo'] ?? ''));

                if ($aPriority !== $bPriority) {
                    return $bPriority <=> $aPriority;
                }

                if ($aSpecificity !== $bSpecificity) {
                    return $bSpecificity <=> $aSpecificity;
                }
            }

            if ($a['isCanonical'] === null) {
                return 1;
            }

            if ($b['isCanonical'] === null) {
                return -1;
            }

            if ($a['salesChannelId'] === null) {
                return 1;
            }

            if ($b['salesChannelId'] === null) {
                return -1;
            }

            return 0;
        });

        $seoPath = ['pathInfo' => $seoPathInfo, 'isCanonical' => false];

        foreach ($seoPaths as $path) {
            $seoPath = $path;
            if ($path['isCanonical']) {
                break;
            }
        }

        if ($queryString !== null && $seoPath['isCanonical'] && isset($seoPath['seoPathInfo']) && \is_string($seoPath['seoPathInfo'])) {
            $exactWithQuery = $seoPathInfo . '?' . $queryString;
            $exactWithSlashAndQuery = $seoPathInfo . '/?' . $queryString;
            if ($seoPath['seoPathInfo'] !== $exactWithQuery && $seoPath['seoPathInfo'] !== $exactWithSlashAndQuery) {
                $seoPath['canonicalPathInfo'] = '/' . ltrim($seoPath['seoPathInfo'], '/');
            }
        }

        if (!$seoPath['isCanonical']) {
            $query = (new QueryBuilder($this->connection))
                ->select('path_info pathInfo', 'seo_path_info seoPathInfo')
                ->from('seo_url')
                ->where('language_id = :language_id')
                ->andWhere('sales_channel_id = :sales_channel_id')
                ->andWhere('path_info = :pathInfo')
                ->andWhere('is_canonical = 1')
                ->setMaxResults(1)
                ->setParameter('language_id', Uuid::fromHexToBytes($languageId))
                ->setParameter('sales_channel_id', Uuid::fromHexToBytes($salesChannelId))
                ->setParameter('pathInfo', '/' . ltrim((string) $seoPath['pathInfo'], '/'));

            $query->setTitle('seo-url::resolve-fallback');

            // we only have an id when the hit seo url was not a canonical url, save the one filter condition
            if (isset($seoPath['id'])) {
                $query->andWhere('id != :id')
                    ->setParameter('id', $seoPath['id']);
            }

            $canonicalQueryResult = $query->executeQuery()->fetchAssociative();
            if ($canonicalQueryResult) {
                $seoPath['canonicalPathInfo'] = '/' . ltrim((string) $canonicalQueryResult['seoPathInfo'], '/');
            }
        }

        $seoPath['pathInfo'] = '/' . ltrim((string) $seoPath['pathInfo'], '/');

        return $seoPath;
    }

    /**
     * @param array<string, mixed> $requestQueryParams
     *
     * @return array{int, int}
     */
    private static function calculateQueryMatch(string $seoPathInfo, string $queryString, array $requestQueryParams, string $storedSeoPathInfo): array
    {
        if ($storedSeoPathInfo === $seoPathInfo . '?' . $queryString || $storedSeoPathInfo === $seoPathInfo . '/?' . $queryString) {
            return [3, \strlen($queryString)];
        }

        $storedQueryString = parse_url($storedSeoPathInfo, \PHP_URL_QUERY);
        if (!\is_string($storedQueryString) || $storedQueryString === '') {
            return [1, 0];
        }

        $storedQueryParams = self::parseQueryParameters($storedQueryString);

        $specificity = 0;
        foreach ($storedQueryParams as $key => $storedValue) {
            if (!\array_key_exists($key, $requestQueryParams)) {
                return [0, 0];
            }

            $requestValue = $requestQueryParams[$key];
            if (!\is_string($storedValue) || !\is_string($requestValue)) {
                return [0, 0];
            }

            if ($storedValue === $requestValue) {
                $specificity += \strlen($storedValue);

                continue;
            }

            // Version-like fallback: allow "5.42" to match configured "5.4", but avoid generic numeric prefixes.
            if (!str_contains($storedValue, '.') || !str_starts_with($requestValue, $storedValue)) {
                return [0, 0];
            }

            $specificity += \strlen($storedValue);
        }

        return [2, $specificity];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseQueryParameters(string $queryString): array
    {
        parse_str($queryString, $rawQueryParams);

        $queryParams = [];
        foreach ($rawQueryParams as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            $queryParams[$key] = $value;
        }

        return $queryParams;
    }
}
