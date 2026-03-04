<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Tests\Unit\Form\Element;

use Hoogi91\Spreadsheets\Form\Element\DataInputElement;
use Hoogi91\Spreadsheets\Service\ExtractorService;
use Hoogi91\Spreadsheets\Service\ReaderService;
use Hoogi91\Spreadsheets\Tests\Unit\Fixtures\FakeResourceFactory;
use JsonSerializable;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\MockObject\MockObject;
use Traversable;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Fluid\Fluid\View\ViewInterface;

/**
 * Testable subclass of DataInputElement that allows injecting a view mock
 * without requiring BackendViewFactory (which is final readonly in TYPO3 v13).
 *
 * Provides a constructor accepting (NodeFactory, array $data) for test convenience,
 * since DataInputElement itself no longer has this constructor in TYPO3 v13
 * (data is injected via setData(), NodeFactory via injectNodeFactory()).
 */
class TestableDataInputElement extends DataInputElement
{
    private static ViewInterface $testView;

    public function __construct(NodeFactory $nodeFactory, array $data)
    {
        // Bypass parent constructor (needs BackendViewFactory, which is final readonly in v13).
        // DataInputElement is a public shared:false service in production; NodeFactory and data
        // are set here manually to allow direct instantiation in unit tests.
        $this->injectNodeFactory($nodeFactory);
        $this->setData($data);
    }

    public static function setTestView(ViewInterface $view): void
    {
        self::$testView = $view;
    }

    protected function createView(array $config): ViewInterface
    {
        self::$testView->assign('inputSize', (int)($config['size'] ?? 0));

        return self::$testView;
    }
}

class DataInputElementTest extends UnitTestCase
{
    private const DEFAULT_UPLOAD_FIELD = 'tx_spreadsheets_assets';
    private const DEFAULT_FIELD_CONF = [
        'renderType' => 'spreadsheetInput',
        'uploadField' => self::DEFAULT_UPLOAD_FIELD,
        'sheetsOnly' => true,
        'size' => 100,
    ];

    private const FILE_REFERENCE_TYPE_MAP = [
        0 => 'null',
        // this file does not exists
        465 => 'xlsx',
        // should work
        589 => 'pdf',
        // should fail
        678 => 'html|exceptionRead', // file reference says html but reader will throw exception
        // should fail on read
        679 => 'csv|exceptionCell', // file reference says csv but rangeToCellArray will throw exception
    ];

    private const DEFAULT_DATA = [
        'tableName' => 'tt_content',
        'databaseRow' => [
            'uid' => 1,
            self::DEFAULT_UPLOAD_FIELD => 465,
        ],
        'processedTca' => [
            'columns' => [
                self::DEFAULT_UPLOAD_FIELD => [ /* any field configuration goes here */],
            ],
        ],
        'parameterArray' => [
            'itemFormElName' => 'my-form-identifier',
            'itemFormElValue' => 'spreadsheet://465?index=1&range=D2%3AG5&direction=vertical',
        ],
    ];

    private const EMPTY_EXPECTED_RESULT = [
        'additionalHiddenFields' => [],
        'additionalInlineLanguageLabelFiles' => [],
        'stylesheetFiles' => [],
        'javaScriptModules' => [],
        'inlineData' => [],
    ];

    private const DEFAULT_EXPECTED_HTML_DATA = [
        'inputSize' => 100,
        'inputName' => 'my-form-identifier',
        'config' => [
            'renderType' => 'spreadsheetInput',
            'uploadField' => 'tx_spreadsheets_assets',
            'sheetsOnly' => true,
            'size' => 100,
        ],
        'sheetFiles' => [
            465 => ['ext' => 'xlsx'],
        ],
        'sheetData' => [
            465 => [
                ['name' => 'Fixture1', 'cells' => ['A1' => 'Hírek']],
                ['name' => 'Fixture2', 'cells' => ['A1' => 'Hírek']],
            ],
        ],
        'valueObject' => 'spreadsheet://465?index=1&range=D2%3AG5&direction=vertical',
    ];

    private ReaderService&MockObject $readerService;

    private ExtractorService&MockObject $extractorService;

    private MockObject&RelationHandler $relationHandler;

    private FakeResourceFactory $fakeResourceFactory;

    /**
     * @var array<mixed>
     */
    private static array $assignedVariables = [];

    protected function setUp(): void
    {
        $trueCallback = static fn (callable $callback) => self::callback(
            static fn () => call_user_func_array($callback, func_get_args()) !== false
        );

        parent::setUp();
        $spreadsheet = (new Xlsx())->load(dirname(__DIR__, 3) . '/Fixtures/01_fixture.xlsx');
        $this->readerService = $this->createMock(ReaderService::class);
        $this->readerService->method('getSpreadsheet')->willReturn($spreadsheet);

        // Create view mock with full assign/render behavior (AbstractTemplateView has getRenderingContext)
        $viewMock = $this->getMockBuilder(\TYPO3Fluid\Fluid\View\AbstractTemplateView::class)
            ->disableOriginalConstructor()
            ->getMock();
        $viewMock->method('assign')->with(
            $trueCallback(static fn ($key) => self::$assignedVariables['_next'] = $key),
            $trueCallback(static function ($value): void {
                self::$assignedVariables[self::$assignedVariables['_next']] = $value;
                unset(self::$assignedVariables['_next']);
            })
        )->willReturnSelf();
        $viewMock->method('assignMultiple')->with($trueCallback(
            static fn ($values) => self::$assignedVariables = array_merge(self::$assignedVariables, $values)
        ))->willReturnSelf();
        $viewMock->method('render')->willReturnCallback(static fn () => self::$assignedVariables);

        // Set up rendering context chain for template path configuration
        $templatePaths = $this->getMockBuilder(\TYPO3Fluid\Fluid\View\TemplatePaths::class)
            ->disableOriginalConstructor()
            ->getMock();
        $renderingContext = $this->createMock(\TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface::class);
        $renderingContext->method('getTemplatePaths')->willReturn($templatePaths);
        $viewMock->method('getRenderingContext')->willReturn($renderingContext);

        // Inject view mock into TestableDataInputElement before construction
        TestableDataInputElement::setTestView($viewMock);

        $this->extractorService = $this->createMock(ExtractorService::class);
        $this->extractorService->method('rangeToCellArray')->willReturn([
            'A1' => file_get_contents(dirname(__DIR__, 3) . '/Fixtures/latin1-content.txt'),
        ]);

        GeneralUtility::addInstance(ReaderService::class, $this->readerService);
        GeneralUtility::addInstance(ExtractorService::class, $this->extractorService);

        // FakeResourceFactory for BackendUtility::resolveFileReferences (uses ResourceFactory singleton)
        FakeResourceFactory::reset();
        $this->fakeResourceFactory = new FakeResourceFactory();
        GeneralUtility::setSingletonInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class, $this->fakeResourceFactory);

        // mock file reference handler to get valid files
        $this->relationHandler = $this->createMock(RelationHandler::class);
        GeneralUtility::addInstance(RelationHandler::class, $this->relationHandler);

        // setup extension TCA
        $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] = [];
        include dirname(__DIR__, 4) . '/Configuration/TCA/Overrides/tt_content.php';

        // BackendUtility::resolveFileReferences needs foreign_table in raw TCA config
        // (TcaPreparation does not run in unit tests)
        $GLOBALS['TCA']['tt_content']['columns']['tx_spreadsheets_assets']['config']['foreign_table'] = 'sys_file_reference';
    }

    protected function tearDown(): void
    {
        FakeResourceFactory::reset();
        GeneralUtility::purgeInstances();
        parent::tearDown();
        self::$assignedVariables = [];
    }

    /**
     * @dataProvider renderDataProvider
     *
     * @param array<mixed> $expected
     * @param array<mixed> $data
     * @param array<mixed> $fieldConfig
     */
    public function testRendering(
        array $expected = self::DEFAULT_EXPECTED_HTML_DATA,
        array $data = self::DEFAULT_DATA,
        array $fieldConfig = self::DEFAULT_FIELD_CONF
    ): void {
        // setup relation and resource factory to return file reference
        $dbData = (array)($data['databaseRow'] ?? []);
        $referenceFieldUid = MathUtility::canBeInterpretedAsInteger($dbData[self::DEFAULT_UPLOAD_FIELD] ?? null)
            ? (int) $dbData[self::DEFAULT_UPLOAD_FIELD]
            : null;
        $referenceFileExtension = self::FILE_REFERENCE_TYPE_MAP[$referenceFieldUid] ?? 'xlsx';
        if ($referenceFileExtension !== 'null') {
            $this->relationHandler->tableArray = [
                'sys_file_reference' => [$referenceFieldUid], // mocked file reference uid to spreadsheet file
            ];

            $fileReferenceMock = $this->createConfiguredMock(
                FileReference::class,
                [
                    'getUid' => $referenceFieldUid,
                    'getExtension' => str_contains($referenceFileExtension, '|exception')
                        ? strtok($referenceFileExtension, '|')
                        : $referenceFileExtension,
                    'toArray' => [
                        'ext' => str_contains($referenceFileExtension, '|exception')
                        ? strtok($referenceFileExtension, '|')
                        : $referenceFileExtension,
                    ],
                ]
            );
            FakeResourceFactory::addFileReference($referenceFieldUid, $fileReferenceMock);

            if (str_contains($referenceFileExtension, '|exceptionRead')) {
                $this->readerService->method('getSpreadsheet')->willThrowException(new ReaderException());
            }
            if (str_contains($referenceFileExtension, '|exceptionCell')) {
                $this->extractorService->method('rangeToCellArray')->willThrowException(new SpreadsheetException());
            }
        } else {
            // no file references exists
            $this->relationHandler->tableArray = [
                'sys_file_reference' => [],
            ];
        }

        // extend config and create element
        $data['parameterArray']['fieldConf']['config'] = $fieldConfig;
        $element = new TestableDataInputElement($this->createMock(NodeFactory::class), $data);

        // extract mocked html variables from rendered data
        $renderedData = $element->render();
        $htmlData = (array)($renderedData['html'] ?? null);
        unset($renderedData['html']);

        $expectedResult = self::EMPTY_EXPECTED_RESULT;
        if (isset($expected['valueObject'])) {
            $expectedResult['stylesheetFiles'] = ['EXT:spreadsheets/Resources/Public/Css/SpreadsheetDataInput.css'];
            $expectedResult['javaScriptModules'][] = JavaScriptModuleInstruction::create(
                '@hoogi91/spreadsheets/SpreadsheetDataInput.js'
            );
            self::assertEquals($expectedResult, $renderedData);
        } else {
            // no value object means we should have an empty form element result
            self::assertEquals($expectedResult, $renderedData);
        }

        // create comparable array
        array_walk_recursive(
            $htmlData,
            static function (&$item): void {
                if ($item instanceof JsonSerializable) {
                    $item = $item->jsonSerialize();
                }
                if (is_object($item) && method_exists($item, 'toArray')) {
                    $item = $item->toArray();
                }
            }
        );
        self::assertEquals($expected, $htmlData);
    }

    /**
     * @return Traversable<string, array<string, mixed>>
     */
    public static function renderDataProvider(): Traversable
    {
        $dataBuilder = static fn (int $type) => array_replace_recursive(
            self::DEFAULT_DATA,
            [
                'databaseRow' => ['uid' => 1, self::DEFAULT_UPLOAD_FIELD => $type],
                'parameterArray' => [
                    'itemFormElValue' => 'spreadsheet://' . $type . '?index=1&range=D2%3AG5&direction=vertical',
                ],
            ]
        );

        yield 'missing upload field' => [
            'expected' => ['inputSize' => 100, 'missingUploadField' => true],
            'data' => ['processedTca' => null] + self::DEFAULT_DATA,
        ];

        yield 'empty references' => [
            'expected' => ['inputSize' => 100, 'nonValidReferences' => true],
            'data' => ['databaseRow' => ['uid' => 1, self::DEFAULT_UPLOAD_FIELD => 0]] + self::DEFAULT_DATA,
        ];

        yield 'missing valid upload reference' => [
            'expected' => ['inputSize' => 100, 'nonValidReferences' => true],
            'data' => $dataBuilder(589),
        ];

        yield 'invalid DSN found' => [
            'expected' => ['inputName' => null, 'valueObject' => ''] + self::DEFAULT_EXPECTED_HTML_DATA,
            'data' => ['parameterArray' => null] + self::DEFAULT_DATA,
        ];

        yield 'spreadsheet read exception' => [
            'expected' => array_replace(
                self::DEFAULT_EXPECTED_HTML_DATA,
                [
                    'sheetFiles' => [678 => ['ext' => 'html']],
                    'sheetData' => [],
                    'valueObject' => 'spreadsheet://678?index=1&range=D2%3AG5&direction=vertical',
                ]
            ),
            'data' => $dataBuilder(678),
        ];

        yield 'spreadsheet range to cell array exception' => [
            'expected' => array_replace(
                self::DEFAULT_EXPECTED_HTML_DATA,
                [
                    'sheetFiles' => [679 => ['ext' => 'csv']],
                    'sheetData' => [679 => []], // because of extraction exception this file sheets are empty
                    'valueObject' => 'spreadsheet://679?index=1&range=D2%3AG5&direction=vertical',
                ]
            ),
            'data' => $dataBuilder(679),
        ];

        yield 'successful input element rendering' => [];

        $templateBuilder = static fn (string $template) => [
            'expected' => array_replace_recursive(
                self::DEFAULT_EXPECTED_HTML_DATA,
                ['config' => ['template' => $template]]
            ),
            'data' => self::DEFAULT_DATA,
            'fieldConfig' => ['template' => $template] + self::DEFAULT_FIELD_CONF,
        ];

        yield 'successful input element rendering with custom template path' => $templateBuilder(
            'EXT:spreadsheets/Resources/Private/Templates/FormElement/DataInput.html'
        );

        yield 'successful input element rendering with unknown template path' => $templateBuilder(
            'EXT:spreadsheets/Resources/Private/Templates/FormElement/ThisFileDoesNotExists.html'
        );
    }
}
