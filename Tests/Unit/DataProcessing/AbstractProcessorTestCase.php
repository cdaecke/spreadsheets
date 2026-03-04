<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Tests\Unit\DataProcessing;

use Hoogi91\Spreadsheets\DataProcessing\AbstractProcessor;
use Hoogi91\Spreadsheets\Service;
use Hoogi91\Spreadsheets\Tests\Unit\Fixtures\FakeResourceFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer as CObjRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

abstract class AbstractProcessorTestCase extends UnitTestCase
{
    protected CObjRenderer&MockObject $cObjRendererMock;

    protected PageRenderer&MockObject $pageRendererMock;

    protected Service\ReaderService&MockObject $readerService;

    protected Service\ExtractorService&MockObject $extractorService;

    protected Service\StyleService&MockObject $styleService;

    protected FakeResourceFactory $fakeResourceFactory;

    protected function setUp(): void
    {
        parent::setUp();

        // add general TYPO3 mock objects
        $this->pageRendererMock = $this->getMockBuilder(PageRenderer::class)->disableOriginalConstructor()->getMock();
        $this->cObjRendererMock = $this->getMockBuilder(CObjRenderer::class)->disableOriginalConstructor()->getMock();
        $this->cObjRendererMock->method('stdWrapValue')->willReturnCallback(
            static fn ($key, array $config, $defaultValue = '') => $config[$key] ?? $defaultValue
        );
        $this->readerService = $this->createMock(Service\ReaderService::class);
        $this->extractorService = $this->createMock(Service\ExtractorService::class);
        $this->styleService = $this->createMock(Service\StyleService::class);
        FakeResourceFactory::reset();
        $this->fakeResourceFactory = new FakeResourceFactory();
    }

    abstract protected function getDataProcessor(): AbstractProcessor;

    abstract protected function validInputExpectations(MockObject $spreadsheetMock): void;

    abstract protected function invalidInputExpectations(): void;

    /**
     * @return array<string, mixed>
     */
    abstract public static function processingDataProvider(): array;

    /**
     * @param array<string, array<mixed>> $processConfig
     * @param array<mixed> $processedData
     * @param array<mixed> $expectedResult
     *
     * @dataProvider processingDataProvider
     */
    public function testProcessing(
        array $processConfig,
        array $processedData = [],
        array $expectedResult = [],
        ?callable $alternativeExpectations = null
    ): void {
        // add page renderer expectation based on ignoreStyle option
        if (isset($processConfig['options.']['ignoreStyles']) && $processConfig['options.']['ignoreStyles'] === 1) {
            $this->pageRendererMock->expects(self::never())->method('addCssInlineBlock');
        } elseif (isset($processConfig['options.']['additionalStyles'])) {
            $this->pageRendererMock->expects(self::atLeastOnce())->method('addCssInlineBlock');
        }

        if ($expectedResult !== []) {
            $referenceMock = $this->createMock(FileReference::class);
            FakeResourceFactory::setDefaultFileReference($referenceMock);

            $spreadsheetMock = $this->createMock(Spreadsheet::class);
            $this->readerService->expects(self::once())
                ->method('getSpreadsheet')
                ->with($referenceMock)
                ->willReturn($spreadsheetMock);

            is_callable($alternativeExpectations)
                ? $alternativeExpectations($spreadsheetMock, $this)
                : $this->validInputExpectations($spreadsheetMock);
        } else {
            $this->readerService->expects(self::never())->method('getSpreadsheet');
            is_callable($alternativeExpectations)
                ? $alternativeExpectations($this)
                : $this->invalidInputExpectations();
        }

        // execute processor
        $result = $this->getDataProcessor()->process(
            $this->cObjRendererMock,
            [],
            $processConfig,
            $processedData
        );
        self::assertEquals($expectedResult + $processedData, $result);
    }
}
