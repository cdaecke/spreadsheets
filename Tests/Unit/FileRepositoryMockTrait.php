<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

trait FileRepositoryMockTrait
{
    /**
     * @return FileReference&MockObject
     */
    private function getFileReferenceMock(
        string $file,
        string $extension = 'xlsx',
        bool $missingOriginalFile = false
    ): MockObject {
        $fileMock = $this->getMockBuilder(File::class)->disableOriginalConstructor()->getMock();
        $fileMock->method('exists')->willReturn(!$missingOriginalFile);

        $mock = $this->getMockBuilder(FileReference::class)->disableOriginalConstructor()->getMock();
        $mock->method('getUid')->willReturn(123);
        $mock->method('getExtension')->willReturn($extension);
        $mock->method('getOriginalFile')->willReturn($fileMock);
        $mock->method('getForLocalProcessing')->willReturn(dirname(__DIR__) . '/Fixtures/' . $file);

        return $mock;
    }

    private function getContainerMock(): MockObject
    {
        assert($this instanceof UnitTestCase);
        $container = $this->getMockBuilder(ContainerInterface::class)->getMock();
        $container->method('has')->willReturn(true);

        return $container;
    }
}
