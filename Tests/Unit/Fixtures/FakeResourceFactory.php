<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Tests\Unit\Fixtures;

use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Mutable state holder for FakeResourceFactory.
 * Kept separate because readonly class cannot have static properties in PHP 8.2.
 */
final class FakeResourceFactoryState
{
    /** @var array<int, FileReference> */
    public static array $fileReferences = [];

    public static ?FileReference $default = null;

    public static function reset(): void
    {
        self::$fileReferences = [];
        self::$default = null;
    }
}

/**
 * Hand-written test double for ResourceFactory.
 * ResourceFactory is declared as `readonly class` in TYPO3 v13 and cannot be mocked by PHPUnit.
 * Mutable state is managed via the companion FakeResourceFactoryState class.
 */
readonly class FakeResourceFactory extends ResourceFactory
{
    public function __construct()
    {
        // Intentionally bypass parent constructor
    }

    public static function reset(): void
    {
        FakeResourceFactoryState::reset();
    }

    public static function addFileReference(int $uid, FileReference $reference): void
    {
        FakeResourceFactoryState::$fileReferences[$uid] = $reference;
    }

    public static function setDefaultFileReference(?FileReference $reference): void
    {
        FakeResourceFactoryState::$default = $reference;
    }

    public function getFileReferenceObject(int $uid, array $fileReferenceData = [], bool $raw = false): FileReference
    {
        if (isset(FakeResourceFactoryState::$fileReferences[$uid])) {
            return FakeResourceFactoryState::$fileReferences[$uid];
        }
        if (FakeResourceFactoryState::$default !== null) {
            return FakeResourceFactoryState::$default;
        }
        throw new ResourceDoesNotExistException(
            sprintf('[Test] File reference with uid %d not found.', $uid),
            1
        );
    }
}
