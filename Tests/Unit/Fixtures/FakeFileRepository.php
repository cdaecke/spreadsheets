<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Tests\Unit\Fixtures;

use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Mutable state holder for FakeFileRepository.
 * Kept separate because readonly class cannot have static properties in PHP 8.2.
 */
final class FakeFileRepositoryState
{
    /** @var FileReference[] */
    public static array $relationsResult = [];

    public static function reset(): void
    {
        self::$relationsResult = [];
    }
}

/**
 * Hand-written test double for FileRepository.
 * FileRepository is declared as `readonly class` in TYPO3 v13 and cannot be mocked by PHPUnit.
 * Mutable state is managed via the companion FakeFileRepositoryState class.
 */
readonly class FakeFileRepository extends FileRepository
{
    public function __construct()
    {
        // Intentionally bypass parent constructor
    }

    public static function reset(): void
    {
        FakeFileRepositoryState::reset();
    }

    /**
     * @param FileReference[] $references
     */
    public static function setFindByRelationResult(array $references): void
    {
        FakeFileRepositoryState::$relationsResult = $references;
    }

    public function findByRelation(string $tableName, string $fieldName, int $uid, ?int $workspaceId = null): array
    {
        return FakeFileRepositoryState::$relationsResult;
    }
}
