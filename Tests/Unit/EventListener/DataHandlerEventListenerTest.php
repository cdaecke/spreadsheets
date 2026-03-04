<?php

declare(strict_types=1);

namespace Hoogi91\Spreadsheets\Tests\Unit\EventListener;

use Hoogi91\Spreadsheets\EventListener\DataHandlerEventListener;
use Hoogi91\Spreadsheets\Tests\Unit\Fixtures\FakeFileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class DataHandlerEventListenerTest extends UnitTestCase
{
    private const DATA_HANDLER_NEW_IDS = [
        'NEW123456' => 123_456,
    ];

    private FakeFileRepository $fakeFileRepository;

    private MockObject&ConnectionPool $connectionPool;

    private DataHandlerEventListener $testEventListener;

    public function setUp(): void
    {
        parent::setUp();

        FakeFileRepository::reset();
        $this->fakeFileRepository = new FakeFileRepository();
        $this->connectionPool = $this->createMock(ConnectionPool::class);

        // Setup TCA so DataHandlerEventListener builds activationTypes from columnsOverrides
        $GLOBALS['TCA']['tt_content']['types']['spreadsheets_table'] = [
            'columnsOverrides' => [
                'bodytext' => [
                    'config' => [
                        'renderType' => 'spreadsheetInput',
                        'uploadField' => 'tx_spreadsheets_assets',
                    ],
                ],
            ],
        ];

        $this->testEventListener = new DataHandlerEventListener($this->fakeFileRepository, $this->connectionPool);

        // default record has no bodytext
        $property = new ReflectionProperty($this->testEventListener, 'records');
        $property->setAccessible(true);
        $property->setValue($this->testEventListener, [123_456 => ['bodytext' => '']]);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    /**
     * @dataProvider datamapProvider
     *
     * @param array<mixed> $hookParams
     * @param array<mixed> $updateParams
     */
    public function testProcessDatamapHook(
        array $hookParams,
        bool $updateTriggered = false,
        array $updateParams = []
    ): void {
        if ($updateTriggered === true) {
            $connectionMock = $this->createMock(Connection::class);
            $connectionMock->expects(self::once())->method('update')->with(...array_values($updateParams));
            $this->connectionPool->expects(self::once())->method('getConnectionForTable')->willReturn($connectionMock);
        } else {
            $this->connectionPool->expects(self::never())->method('getConnectionForTable');
        }

        // append data handler to hook params
        $dataHandlerMock = $this->createMock(DataHandler::class);
        $dataHandlerMock->substNEWwithIDs = self::DATA_HANDLER_NEW_IDS;
        $hookParams[] = $dataHandlerMock;
        $this->testEventListener->processDatamap_afterDatabaseOperations(...array_values($hookParams));
    }

    /**
     * @dataProvider datamapWithFileReferenceProvider
     *
     * @param array<mixed> $hookParams
     * @param array<mixed> $references
     * @param array<mixed> $updateParams
     */
    public function testProcessDatamapHookWithFileRelations(
        array $hookParams,
        array $references,
        bool $updateTriggered = false,
        array $updateParams = [],
        ?callable $closure = null
    ): void {
        // update file repository fake with given reference uid's
        $references = array_map(function ($reference) {
            $mock = $this->getMockBuilder(FileReference::class)->disableOriginalConstructor()->getMock();
            $mock->method('getUid')->willReturn($reference);

            return $mock;
        }, $references);
        FakeFileRepository::setFindByRelationResult($references);

        // update statically saved entries got with backend utility
        if ($closure !== null) {
            $closure($this->testEventListener);
        }

        // now start process datamap hook test
        $this->testProcessDatamapHook($hookParams, $updateTriggered, $updateParams);
    }

    /**
     * @return array<string, array<string, int|string|bool|array<mixed>>>
     */
    public static function datamapProvider(): array
    {
        return [
            '[NEW/UPDATED] ID is not mapped or integer' => [
                'hookParams' => self::hookParams(['id' => 'NEW123abc']),
            ],
            '[NEW/UPDATED] is not in tt_content' => [
                'hookParams' => self::hookParams(['table' => 'pages']),
            ],
            '[NEW/UPDATED] is not in valid status' => [
                'hookParams' => self::hookParams(['status' => 'unknown']),
            ],
            '[NEW] is not a spreadsheet table' => [
                'hookParams' => self::hookParams(['fields' => ['CType' => 'textpic']]),
            ],
            '[UPDATED] is not a spreadsheet table' => [
                // force call backend utility which will return null on default
                'hookParams' => self::hookParams(['status' => 'update', 'fields' => ['CType' => null]]),
            ],
            '[NEW] has clean assets field' => [
                'hookParams' => self::hookParams(['fields' => ['tx_spreadsheets_assets' => 0]]),
            ],
            '[UPDATED] has clean assets field' => [
                'hookParams' => self::hookParams(['status' => 'update', 'fields' => ['tx_spreadsheets_assets' => 0]]),
                'updateTriggered' => true,
                'updateParams' => ['tt_content', ['bodytext' => ''], ['uid' => 123_456]],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function datamapWithFileReferenceProvider(): array
    {
        return [
            '[NEW/UPDATED] file reference is not found' => [
                'hookParams' => self::hookParams(),
                'references' => [],
                'updateTriggered' => false,
                'updateParams' => [],
            ],
            '[NEW/UPDATED] bodytext is not empty' => [
                'hookParams' => self::hookParams(),
                'references' => [123],
                'updateTriggered' => false,
                'updateParams' => [],
                'closure' => static function ($listener): void {
                    $property = new ReflectionProperty($listener, 'records');
                    $property->setAccessible(true);
                    $property->setValue($listener, [
                        123_456 => [
                            'CType' => 'spreadsheets_table',
                            'tx_spreadsheets_assets' => 1,
                            'bodytext' => 'spreadsheet://456',
                        ],
                    ]);
                },
            ],
            '[NEW] saved and bodytext gets updated' => [
                // uses file repo fake reference ID
                'hookParams' => self::hookParams(),
                'references' => [456],
                'updateTriggered' => true,
                'updateParams' => ['tt_content', ['bodytext' => 'spreadsheet://456'], ['uid' => 123_456]],
            ],
        ];
    }

    /**
     * @param array<string, string|array<mixed>> $data
     *
     * @return array<string, int|string|array<mixed>>
     */
    private static function hookParams(array $data = []): array
    {
        return [
            'status' => $data['status'] ?? 'new',
            'table' => $data['table'] ?? 'tt_content',
            'id' => $data['id'] ?? array_keys(self::DATA_HANDLER_NEW_IDS)[0],
            'fields' => array_replace_recursive(
                [
                    'CType' => 'spreadsheets_table',
                    'tx_spreadsheets_assets' => 1,
                    // on default every request has one asset
                ],
                (array)($data['fields'] ?? [])
            ),
        ];
    }
}
